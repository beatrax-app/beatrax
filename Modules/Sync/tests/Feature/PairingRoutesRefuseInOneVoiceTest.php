<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Uri\Http as HttpUri;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Transport\PairingFramePullHandler;
use Modules\Sync\Internal\Transport\PairingFrameRequestHandler;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

// Three routes on one listener answer a stranger, and what they answer has to
// be one voice: an unknown token, an expired one, a malformed frame and one
// belonging to somebody else must be a single indistinguishable "no", and a
// throttled caller must read the same refusal on whichever route it hit. The
// bodies used to be written out once per handler, which is how one of them
// would eventually have said something the other two did not.

function oneVoiceLimiter(int $limit): PairingOfferRateLimiter
{
    return app(PairingOfferRateLimiter::class)->withLimit($limit);
}

function oneVoiceNext(): RequestHandler
{
    return new ClosureRequestHandler(static fn (): AmpResponse => new AmpResponse(101, [], 'delegated-to-websocket'));
}

function oneVoiceRequest(string $method, string $path, string $body = ''): AmpRequest
{
    $client = Mockery::mock(AmpClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('198.51.100.9', 45123));

    return new AmpRequest($client, $method, HttpUri::new("http://192.0.2.10:51337{$path}"), [], $body);
}

/**
 * @return array{status: int, contentType: ?string, body: string}
 */
function oneVoiceAnswer(RequestHandler $handler, AmpRequest $request): array
{
    $response = $handler->handleRequest($request);

    return [
        'status' => $response->getStatus(),
        'contentType' => $response->getHeader('content-type'),
        'body' => buffer($response->getBody()),
    ];
}

function oneVoiceOfferHandler(int $limit): PairingOfferRequestHandler
{
    return new PairingOfferRequestHandler(oneVoiceNext(), app(PairingOfferService::class), oneVoiceLimiter($limit), 1);
}

function oneVoiceFrameHandler(int $limit): PairingFrameRequestHandler
{
    return new PairingFrameRequestHandler(oneVoiceNext(), app(PairingFrameApplier::class), oneVoiceLimiter($limit), 1);
}

function oneVoicePullHandler(int $limit): PairingFramePullHandler
{
    return new PairingFramePullHandler(
        oneVoiceNext(),
        app(PairingPeerOutbox::class),
        oneVoiceLimiter($limit),
        app(PairingPullAuthorizer::class),
        1,
    );
}

it('refuses a throttled caller in the same words on every pairing route', function (): void {
    $routes = [
        [oneVoiceOfferHandler(1), 'GET', '/pair/offer?token=deadbeef', ''],
        [oneVoiceFrameHandler(1), 'POST', '/pair/frame', '{}'],
        [oneVoicePullHandler(1), 'GET', '/pair/frames?device=nobody&proof=nothing', ''],
    ];

    foreach ($routes as [$handler, $method, $path, $body]) {
        oneVoiceAnswer($handler, oneVoiceRequest($method, $path, $body));
        $refused = oneVoiceAnswer($handler, oneVoiceRequest($method, $path, $body));

        expect($refused['status'])->toBe(429);
        expect($refused['contentType'])->toBe('application/json');
        expect($refused['body'])->toBe('{"error":"rate_limited"}');
    }
});

it('refuses an unknown token and a malformed frame with one indistinguishable body', function (): void {
    $offerRefusal = oneVoiceAnswer(oneVoiceOfferHandler(10), oneVoiceRequest('GET', '/pair/offer?token=deadbeef'));
    $frameRefusal = oneVoiceAnswer(oneVoiceFrameHandler(10), oneVoiceRequest('POST', '/pair/frame', 'not-json-at-all'));

    expect($offerRefusal['status'])->toBe(404);
    expect($offerRefusal['body'])->toBe('{"error":"not_found"}');
    expect($frameRefusal['status'])->toBe($offerRefusal['status']);
    expect($frameRefusal['body'])->toBe($offerRefusal['body']);
    expect($frameRefusal['contentType'])->toBe($offerRefusal['contentType']);
});

// The two amphp listeners in this module wrote these three literals out five
// times between them. One home is the whole point of the seam, so the literal
// reappearing anywhere else is the drift starting again.
it('leaves the shared JSON literals in exactly one file', function (): void {
    $offenders = [];

    foreach (['Modules/Sync/Internal/Transport', 'Modules/Sync/Commands'] as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_ends_with($path, 'AnswersInJson.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach (['{"error":"not_found"}', '{"error":"rate_limited"}', "'application/json'"] as $literal) {
                if (str_contains($source, $literal)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' — '.$literal;
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
