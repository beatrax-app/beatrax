<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\CursorExpiredException;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

beforeEach(function (): void {
    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::createFromTimestamp(1_700_000_000);
        }
    };

    $this->mapper = new GraphErrorMapper($this->clock);
});

it('maps a 401 to the grant failure that stops the retry, not a generic error', function (): void {
    // The client calls ensureFreshAccessToken() before every request, so a 401
    // is the provider refusing a token we just refreshed. transitionOnScanError
    // re-throws anything it does not recognise, and only a condition a later
    // attempt could clear may leave through it — this one never clears.
    $response = new Response(
        HttpResponse::HTTP_UNAUTHORIZED,
        [],
        (string) json_encode(['error' => ['message' => 'Access token is empty.']]),
    );

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(InvalidGrantException::class)
        ->and($e->getMessage())->toContain('Access token is empty.');
});

it('still treats a 500 as retryable, because a later attempt can clear it', function (): void {
    $response = new Response(
        HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
        [],
        (string) json_encode(['error' => ['message' => 'backend hiccup']]),
    );

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->not->toBeInstanceOf(InvalidGrantException::class)
        ->and($e)->toBeInstanceOf(RuntimeException::class);
});

it('maps a null response to a runtime exception naming the context', function (): void {
    $e = $this->mapper->mapErrorResponse(null, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toContain('provider returned no response');
});

it('maps a 429 with a numeric Retry-After to a rate-limit sentinel carrying the delay', function (): void {
    $response = new Response(
        HttpResponse::HTTP_TOO_MANY_REQUESTS,
        ['Retry-After' => '90'],
        (string) json_encode(['error' => ['message' => 'throttled']]),
    );

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RateLimitedException::class)
        ->and($e->retryAfterSeconds)->toBe(90)
        ->and($e->getMessage())->toContain('throttled');
});

it('reads an HTTP-date Retry-After against the injected clock', function (): void {
    $future = gmdate('D, d M Y H:i:s \G\M\T', 1_700_000_000 + 120);
    $response = new Response(HttpResponse::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $future], '');

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RateLimitedException::class)
        ->and($e->retryAfterSeconds)->toBe(120);
});

it('falls back to 60 seconds when Retry-After is absent', function (): void {
    $response = new Response(HttpResponse::HTTP_TOO_MANY_REQUESTS, [], '');

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RateLimitedException::class)
        ->and($e->retryAfterSeconds)->toBe(60);
});

it('maps a 410 on a delta call to a cursor-expired sentinel', function (): void {
    $response = new Response(HttpResponse::HTTP_GONE, [], (string) json_encode(['error' => ['code' => 'syncStateNotFound']]));

    $e = $this->mapper->mapErrorResponse($response, 'GET delta', expectsDelta: true);

    expect($e)->toBeInstanceOf(CursorExpiredException::class);
});

it('maps a 410 outside a delta call to a plain runtime exception', function (): void {
    $response = new Response(HttpResponse::HTTP_GONE, [], '');

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e)->not->toBeInstanceOf(CursorExpiredException::class)
        ->and($e->getMessage())->toContain('HTTP 410');
});

it('prefers error.message, then error.code, then a fixed unrecognised marker', function (): void {
    $withMessage = new Response(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, [], (string) json_encode(['error' => ['message' => 'boom', 'code' => 'X']]));
    $withCode = new Response(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, [], (string) json_encode(['error' => ['code' => 'onlyCode']]));
    $withoutError = new Response(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, [], (string) json_encode(['notError' => true]));

    expect($this->mapper->mapErrorResponse($withMessage, 'ctx')->getMessage())->toContain('boom')
        ->and($this->mapper->mapErrorResponse($withCode, 'ctx')->getMessage())->toContain('onlyCode')
        ->and($this->mapper->mapErrorResponse($withoutError, 'ctx')->getMessage())->toContain('unrecognised error body');
});

it('reports an empty body and passes a non-JSON body through the safe-message cap', function (): void {
    $empty = new Response(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, [], '');
    $nonJson = new Response(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, [], 'plain text failure');

    expect($this->mapper->mapErrorResponse($empty, 'ctx')->getMessage())->toContain('no body returned')
        ->and($this->mapper->mapErrorResponse($nonJson, 'ctx')->getMessage())->toContain('plain text failure');
});

it('caps and single-lines a message via safeMessage', function (): void {
    expect($this->mapper->safeMessage("line one\nline two"))->not->toContain("\n");
});

// Microsoft documents 503 Service Unavailable and 509 Bandwidth Limit
// Exceeded as throttling responses carrying Retry-After. Landing them on the
// default arm flips the inbox to `error` (a red badge, not "rate limited"),
// never bumps retry_attempts, and throws the provider's own delay away.
it('maps a documented Graph throttling status to the rate-limit sentinel', function (int $status): void {
    $response = new Response(
        $status,
        ['Retry-After' => '45'],
        (string) json_encode(['error' => ['message' => 'Application is over its quota.']]),
    );

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RateLimitedException::class)
        ->and($e->retryAfterSeconds)->toBe(45)
        ->and($e->getMessage())->toContain('over its quota');
})->with([
    'service unavailable' => [503],
    'bandwidth limit exceeded' => [509],
]);

it('still reports an unrelated 5xx as a plain runtime failure', function (): void {
    $response = new Response(500, [], (string) json_encode(['error' => ['message' => 'graph exploded']]));

    $e = $this->mapper->mapErrorResponse($response, 'GET /me/messages');

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e)->not->toBeInstanceOf(RateLimitedException::class);
});
