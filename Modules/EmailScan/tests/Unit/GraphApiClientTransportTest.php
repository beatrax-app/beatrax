<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\Core\Public\Support\FileHead;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Exceptions\UnsafeProviderRequestException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Http\Message\StreamInterface;

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

    $this->makeRecordingClient = function (array $responses): GraphApiClient {
        $transactions = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($transactions));

        $client = new GraphApiClient(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GraphErrorMapper($this->clock),
            new GuzzleClient(['handler' => $stack]),
        );

        // The history container fills as requests flow, so it is read through
        // a closure rather than captured by value here.
        $this->recorded = static function () use (&$transactions): array {
            return array_map(
                static fn (array $tx): string => (string) $tx['request']->getUri(),
                $transactions,
            );
        };

        return $client;
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

// Content-Length is the only thing that can settle the size before the bytes
// land in one PHP string, so this body answers getSize() and refuses to be
// read: reaching read() at all is the defect.
function graphUnreadableBody(int $declaredSize): StreamInterface
{
    return new class($declaredSize) implements StreamInterface
    {
        public function __construct(private int $declaredSize) {}

        public function __toString(): string
        {
            return $this->read(PHP_INT_MAX);
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return $this->declaredSize;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return false;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            throw new RuntimeException('the mailbox decided how much of this device to spend');
        }

        public function getContents(): string
        {
            return $this->read(PHP_INT_MAX);
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
}

// Counts what it hands out, so a reader that only wants the front of a body
// can be held to that rather than trusted to stop.
function graphCountingBody(string $data): StreamInterface
{
    return new class($data) implements StreamInterface
    {
        public int $bytesRead = 0;

        private int $position = 0;

        public function __construct(private string $data) {}

        public function __toString(): string
        {
            return $this->getContents();
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return strlen($this->data);
        }

        public function tell(): int
        {
            return $this->position;
        }

        public function eof(): bool
        {
            return $this->position >= strlen($this->data);
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $chunk = substr($this->data, $this->position, $length);
            $this->position += strlen($chunk);
            $this->bytesRead += strlen($chunk);

            return $chunk;
        }

        public function getContents(): string
        {
            return $this->read(strlen($this->data) - $this->position);
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
}

it('refuses a raw message whose declared length is past the ceiling, without reading the body', function (): void {
    $client = ($this->makeClient)([
        new Response(200, ['Content-Type' => 'message/rfc822'], graphUnreadableBody(26 * 1024 * 1024)),
    ]);

    expect(fn () => $client->getRawMessage(1, 'AAA-BBB_CCC'))
        ->toThrow(BoundedReadException::class, 'is past the 26214400-byte ceiling');
});

// The paging calls take the same door: a page is small in practice, but how
// small is the far end's to decide, and json_decode holds the parsed copy
// alongside the string it parsed.
it('refuses a paging response whose declared length is past the ceiling, without reading it', function (): void {
    $client = ($this->makeClient)([
        new Response(200, ['Content-Type' => 'application/json'], graphUnreadableBody(26 * 1024 * 1024)),
    ]);

    expect(fn () => $client->deltaPage(1, null))
        ->toThrow(BoundedReadException::class, 'is past the 26214400-byte ceiling');
});

// The status is what the mapper acts on; the body only supplies the sentence.
// A gateway answering a failure with megabytes must cost the front of it and
// no more, and the mapped exception must still be the one the status names.
it('reads only the front of an oversized error body and still maps the status', function (): void {
    $body = graphCountingBody(str_repeat('x', 4 * FileHead::BYTES));
    $client = ($this->makeClient)([
        new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '30'], $body),
    ]);

    expect(fn () => $client->getRawMessage(1, 'AAA-BBB_CCC'))
        ->toThrow(RateLimitedException::class);

    expect($body->bytesRead)->toBeLessThanOrEqual(FileHead::BYTES);
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

// Guzzle's `query` option REPLACES the URI's own query string rather than
// merging into it, and an empty array still counts as "set". A nextLink or
// deltaLink sent that way arrives with $skiptoken/$deltatoken/$filter gone,
// so the provider answers page one forever.
it('follows a nextLink with its query string intact', function (): void {
    $nextLink = 'https://graph.microsoft.com/v1.0/me/messages?%24skiptoken=SKIP123&%24top=100';
    $client = ($this->makeRecordingClient)([graphJson(['value' => []])]);

    $client->listSenderMessagesPaged(1, ['billing@shop.example'], new DateTimeImmutable('2026-01-01T00:00:00Z'), $nextLink);

    expect(($this->recorded)())->toBe([$nextLink]);
});

it('follows a stored delta link with its $deltatoken intact', function (): void {
    $deltaLink = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?%24deltatoken=STORED_T0';
    $client = ($this->makeRecordingClient)([graphJson(['value' => []])]);

    $client->deltaPage(1, $deltaLink);

    expect(($this->recorded)())->toBe([$deltaLink]);
});

it('follows a discovery nextLink with its $search and $skiptoken intact', function (): void {
    $nextLink = 'https://graph.microsoft.com/v1.0/me/messages?%24search=%22subject%3A(%22receipt%22)%22&%24skiptoken=SKIP9';
    $client = ($this->makeRecordingClient)([graphJson(['value' => []])]);

    $client->listDiscoveryCandidatesPaged(1, ['receipt'], [], $nextLink);

    expect(($this->recorded)())->toBe([$nextLink]);
});

it('still sends the composed query on a first-page call', function (): void {
    $client = ($this->makeRecordingClient)([graphJson(['value' => []])]);

    $client->deltaPage(1, null, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    expect(($this->recorded)()[0])->toContain('%24filter=receivedDateTime%20ge%202026-01-01T00%3A00%3A00Z');
});
