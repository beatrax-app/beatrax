<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\CursorExpiredException;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/*
 * GraphErrorMapper unit coverage.
 *
 * The mapper owns the Graph error surface split out of GraphApiClient:
 * translating a non-2xx response into the module's typed sentinels,
 * parsing Retry-After against the injected clock, and capping provider
 * error text. Each arm is driven directly here so the collaborator's
 * branches are exercised independently of the HTTP client.
 */

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
