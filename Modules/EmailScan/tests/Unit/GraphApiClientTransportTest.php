<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Exceptions\UnsafeProviderRequestException;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

beforeEach(function (): void {
    $this->secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return new InboxCredentials(
                inboxId: $inboxId,
                provider: 'microsoft',
                refreshToken: 'fixture-refresh',
                scope: 'Mail.Read offline_access User.Read',
                expiresAt: (new DateTimeImmutable)->setTimestamp(time() + 3600),
                accessToken: 'fixture-access-token',
            );
        }
    };

    $this->oauth = new class extends MicrosoftOAuthProvider
    {
        public function __construct() {}
    };

    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::createFromTimestamp(time());
        }
    };

    $this->makeClient = function (array $responses): GraphApiClient {
        return new GraphApiClient(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GraphErrorMapper($this->clock),
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    };
});

function graphJson(array $payload): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

it('returns the messages on a page and the link to the next one', function (): void {
    $client = ($this->makeClient)([
        graphJson([
            'value' => [
                ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
                ['id' => 'BBB', 'subject' => 'Invoice', 'receivedDateTime' => '2026-01-02T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=abc',
        ]),
    ]);

    $page = $client->listSenderMessagesPaged(
        1,
        ['billing@shop.example'],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
    );

    expect($page['messages'])->toHaveCount(2)
        ->and($page['nextLink'])->toBe('https://graph.microsoft.com/v1.0/me/messages?$skiptoken=abc');
});

it('reports no next link on the last page, which is what ends a scan', function (): void {
    $client = ($this->makeClient)([
        graphJson(['value' => [
            ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
        ]]),
    ]);

    $page = $client->listSenderMessagesPaged(
        1,
        ['billing@shop.example'],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
    );

    // A nextLink that never becomes null is an infinite scan; the absence of
    // the key has to read as "done" rather than as an empty string.
    expect($page['nextLink'])->toBeNull()
        ->and($page['messages'])->toHaveCount(1);
});

it('asks for nothing at all when there are no senders to scan for', function (): void {
    // No HTTP responses queued: reaching the transport here would throw, which
    // is the assertion — an empty pattern list must not become an unfiltered
    // "fetch this mailbox" request.
    $client = ($this->makeClient)([]);

    $page = $client->listSenderMessagesPaged(1, [], new DateTimeImmutable('2026-01-01T00:00:00Z'), null);

    expect($page)->toBe(['messages' => [], 'nextLink' => null]);
});

it('fetches a raw message as bytes rather than a JSON envelope', function (): void {
    $raw = "From: billing@shop.example\r\nSubject: Receipt\r\n\r\nBody text";
    $client = ($this->makeClient)([
        new Response(200, ['Content-Type' => 'text/plain'], $raw),
    ]);

    expect($client->getRawMessage(1, 'AAA-BBB_CCC'))->toBe($raw);
});

it('refuses a message id that could carry a path traversal', function (string $id): void {
    $client = ($this->makeClient)([]);

    $client->getRawMessage(1, $id);
})->with([
    'traversal' => ['../../me/messages'],
    'slash' => ['AAA/BBB'],
    'space' => ['AAA BBB'],
])->throws(UnsafeProviderRequestException::class, 'allow-list validation');

it('carries the delta link forward so the next scan resumes where this one stopped', function (): void {
    $client = ($this->makeClient)([
        graphJson([
            'value' => [
                ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=xyz',
        ]),
    ]);

    $result = $client->deltaPage(1, null);

    expect($result['deltaLink'])->toBe('https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=xyz');
});

// A proxy or gateway in front of Graph can return something that parses as
// JSON but is not the documented {"error": {"code", "message"}} shape. The
// client must keep its footing rather than raise a type error mid-failure.
it('falls back to a fixed phrase when the error body is not the documented shape', function (string $body): void {
    $client = ($this->makeClient)([
        new Response(500, ['Content-Type' => 'application/json'], $body),
    ]);

    expect(fn () => $client->getRawMessage(1, 'AAA-BBB_CCC'))
        ->toThrow(RuntimeException::class, 'returned HTTP 500 — unrecognised error body');
})->with([
    'valid JSON that is not an object' => ['"just a string"'],
    'error is not an object' => ['{"error":"oops"}'],
    'error object carries neither message nor code' => ['{"error":{}}'],
]);

// A paging call reaches getJson's BadResponseException arm, a different route
// to the same error mapper getRawMessage uses.
it('maps a Graph error status on a paging call through the error mapper', function (): void {
    $client = ($this->makeClient)([
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['message' => 'graph exploded']])),
    ]);

    expect(fn () => $client->deltaPage(1, null))
        ->toThrow(RuntimeException::class, 'graph exploded');
});

// A failure that never produced a response — DNS, refused connection, timeout
// — reaches a different arm, where Graph's error envelope does not exist and
// the message is the transport's own, capped by safeMessage().
it('reports a transport failure with no response while fetching a raw message', function (): void {
    $client = ($this->makeClient)([
        new ConnectException('could not resolve host', new Request('GET', 'https://graph.microsoft.com/v1.0/me')),
    ]);

    expect(fn () => $client->getRawMessage(1, 'AAA-BBB_CCC'))
        ->toThrow(RuntimeException::class, 'HTTP error fetching raw message');
});

it('reports a transport failure with no response while paging', function (): void {
    $client = ($this->makeClient)([
        new ConnectException('connection timed out', new Request('GET', 'https://graph.microsoft.com/v1.0/me')),
    ]);

    expect(fn () => $client->deltaPage(1, null))
        ->toThrow(RuntimeException::class, 'HTTP error against');
});

// A 200 carrying something that is not JSON: a captive portal, a proxy error
// page, a truncated body. The status says success, so nothing before this
// point objects.
it('reports a successful response whose body is not JSON', function (): void {
    $client = ($this->makeClient)([
        new Response(200, ['Content-Type' => 'application/json'], '<html>not json at all</html>'),
    ]);

    expect(fn () => $client->deltaPage(1, null))
        ->toThrow(RuntimeException::class, 'failed to decode Graph response JSON');
});

// Reaching the transport with no token produces a 401 the caller would mistake
// for an expired grant, so it is refused first.
it('refuses to act on an inbox with no persisted credentials', function (): void {
    $secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return null;
        }
    };

    $client = new GraphApiClient(
        $secrets,
        $this->oauth,
        $this->clock,
        $this->createStub(EventsDispatcher::class),
        $this->createStub(DatabaseManager::class),
        new GraphErrorMapper($this->clock),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    );

    expect(fn () => $client->getRawMessage(7, 'AAA-BBB_CCC'))
        ->toThrow(RuntimeException::class, 'no OAuth credentials persisted for inbox 7');
});
