<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\EmailScan\Internal\Clients\CursorExpiredException;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailInboxResources;
use Modules\EmailScan\Internal\Clients\GmailRawDecodeException;
use Modules\EmailScan\Internal\Clients\MessageUnavailableException;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

// Unlike the Graph client this one talks through the Google SDK, so the seam
// is the SDK's own HTTP client rather than a Guzzle instance.
beforeEach(function (): void {
    $this->secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return new InboxCredentials(
                inboxId: $inboxId,
                provider: 'gmail',
                refreshToken: 'fixture-refresh',
                scope: 'https://www.googleapis.com/auth/gmail.readonly',
                expiresAt: (new DateTimeImmutable)->setTimestamp(time() + 3600),
                accessToken: 'fixture-access-token',
            );
        }
    };

    $this->oauth = new class extends GoogleOAuthProvider
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

    $this->makeRecordingClient = function (array $responses): GmailApiClient {
        $transactions = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($transactions));

        // The history container fills as requests flow, so it is read through
        // a closure rather than captured by value here.
        $this->recorded = static function () use (&$transactions): array {
            return array_map(
                static fn (array $tx): string => (string) $tx['request']->getUri(),
                $transactions,
            );
        };

        return new GmailApiClient(new GmailInboxResources(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GuzzleClient(['handler' => $stack]),
        ));
    };

    $this->makeClient = function (array $responses): GmailApiClient {
        return new GmailApiClient(new GmailInboxResources(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        ));
    };
});

function gmailJson(array $payload): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

it('lists the messages on a page and carries the page token forward', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'messages' => [
                ['id' => 'm1', 'threadId' => 't1'],
                ['id' => 'm2', 'threadId' => 't1'],
            ],
            'nextPageToken' => 'page-2-token',
            'resultSizeEstimate' => 2,
        ]),
    ]);

    $page = $client->listSenderMessages(1, ['billing@shop.example'], null);

    expect($page['messages'])->toHaveCount(2)
        ->and($page['messages'][0]['id'])->toBe('m1')
        ->and($page['nextPageToken'])->toBe('page-2-token');
});

it('reports no page token on the last page', function (): void {
    $client = ($this->makeClient)([
        gmailJson(['messages' => [['id' => 'm1', 'threadId' => 't1']], 'resultSizeEstimate' => 1]),
    ]);

    $page = $client->listSenderMessages(1, ['billing@shop.example'], null);

    expect($page['nextPageToken'])->toBeNull();
});

it('decodes the base64url body Gmail returns into RFC 822 bytes', function (): void {
    $raw = "From: billing@shop.example\r\nSubject: Receipt\r\n\r\nBody text";
    // Gmail returns base64url, which differs from base64 in two characters;
    // decoding with the wrong alphabet corrupts any message containing them.
    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    $client = ($this->makeClient)([
        gmailJson(['id' => 'm1', 'raw' => $encoded]),
    ]);

    expect($client->getRawMessage(1, 'm1'))->toBe($raw);
});

// A dedicated exception rather than a generic one, so the scan pipeline can
// tell a corrupt-source fetch apart from a transport fault.
it('raises a dedicated decode failure when Gmail returns a non-base64url raw payload', function (): void {
    $client = ($this->makeClient)([
        gmailJson(['id' => 'm1', 'raw' => '@@not-base64url@@']),
    ]);

    expect(fn () => $client->getRawMessage(1, 'm1'))
        ->toThrow(GmailRawDecodeException::class, 'failed to base64url-decode');
});

// base64UrlDecode makes three more copies of the payload before the plaintext
// exists, so the ceiling has to be read off the resource rather than off the
// decode. The raw here is one no decode would accept: getting the size refusal
// instead of the decode failure is what says nothing tried to decode it.
it('refuses a Gmail message whose stated size is past the ceiling, before decoding a byte', function (): void {
    $client = ($this->makeClient)([
        gmailJson(['id' => 'm1', 'raw' => '@@not-base64url@@', 'sizeEstimate' => 26 * 1024 * 1024]),
    ]);

    expect(fn () => $client->getRawMessage(1, 'm1'))
        ->toThrow(BoundedReadException::class, 'is past the 26214400-byte ceiling');
});

// Headers only, never a body byte. A hit with no parseable From header is
// dropped because it could never promote to a known sender.
it('walks discovery candidates, fetching headers-only metadata and dropping senderless hits', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'messages' => [
                ['id' => 'd1', 'threadId' => 't1'],
                ['id' => 'd2', 'threadId' => 't2'],
                ['id' => 'd3', 'threadId' => 't3'],
            ],
            'resultSizeEstimate' => 3,
        ]),
        gmailJson([
            'id' => 'd1',
            'internalDate' => '1700000000000',
            'payload' => ['headers' => [
                ['name' => 'From', 'value' => 'Shop Billing <billing@shop.example>'],
                ['name' => 'Date', 'value' => 'Tue, 14 Nov 2023 22:13:20 +0000'],
            ]],
        ]),
        gmailJson([
            'id' => 'd2',
            'internalDate' => '1700000500000',
            'payload' => ['headers' => [
                ['name' => 'From', 'value' => 'noreply@bank.example'],
            ]],
        ]),
        gmailJson([
            'id' => 'd3',
            'internalDate' => '1700000900000',
            'payload' => ['headers' => [
                ['name' => 'Subject', 'value' => 'no from header here'],
            ]],
        ]),
    ]);

    $page = $client->listDiscoveryCandidates(1, ['invoice', 'receipt'], ['@ads.example']);

    expect($page['messages'])->toHaveCount(2)
        ->and($page['messages'][0]['id'])->toBe('d1')
        ->and($page['messages'][0]['fromAddress'])->toBe('billing@shop.example')
        ->and($page['messages'][0]['fromName'])->toBe('Shop Billing')
        ->and($page['messages'][1]['fromAddress'])->toBe('noreply@bank.example')
        ->and($page['messages'][1]['fromName'])->toBeNull()
        ->and($page['nextPageToken'])->toBeNull();
});

it('builds a discovery query with no exclude clause and carries the page token forward', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'messages' => [['id' => 'd9', 'threadId' => 't9']],
            'nextPageToken' => 'disc-page-2',
            'resultSizeEstimate' => 1,
        ]),
        gmailJson([
            'id' => 'd9',
            'internalDate' => '1700001000000',
            'payload' => ['headers' => [['name' => 'From', 'value' => 'sales@merchant.example']]],
        ]),
    ]);

    $page = $client->listDiscoveryCandidates(1, ['statement'], []);

    expect($page['messages'])->toHaveCount(1)
        ->and($page['messages'][0]['fromAddress'])->toBe('sales@merchant.example')
        ->and($page['nextPageToken'])->toBe('disc-page-2');
});

// The Google exception has to become the module's typed sentinel, or the
// caller never honours the backoff envelope.
it('surfaces a rate-limit sentinel when a discovery metadata fetch is throttled', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'messages' => [['id' => 'd1', 'threadId' => 't1']],
            'resultSizeEstimate' => 1,
        ]),
        new Response(403, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => [
                'code' => 403,
                'message' => 'User-rate limit exceeded.',
                'errors' => [['domain' => 'usageLimits', 'reason' => 'rateLimitExceeded', 'message' => 'User-rate limit exceeded.']],
            ],
        ])),
    ]);

    expect(fn () => $client->listDiscoveryCandidates(1, ['invoice'], []))
        ->toThrow(RateLimitedException::class);
});

it('surfaces a 401 as the grant failure that stops the retry, not a bare SDK error', function (): void {
    // The token is refreshed before the call, so a 401 is Google refusing one
    // just minted. IncrementalScanJob re-throws what it does not recognise and
    // the queue tries again; nothing a later attempt does clears this. Left
    // generic it spent 78 failed jobs in a day on a credential that never works.
    $client = ($this->makeClient)([
        new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => [
                'code' => 401,
                'message' => 'Request had invalid authentication credentials.',
                'status' => 'UNAUTHENTICATED',
            ],
        ])),
    ]);

    expect(fn () => $client->listDiscoveryCandidates(1, ['invoice'], []))
        ->toThrow(InvalidGrantException::class);
});

// Reaching Google with no token comes back as a 401 the caller would mistake
// for an expired grant, so it is refused before the service is built.
it('refuses to act on an inbox with no persisted credentials', function (): void {
    $secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return null;
        }
    };

    $client = new GmailApiClient(new GmailInboxResources(
        $secrets,
        $this->oauth,
        $this->clock,
        $this->createStub(EventsDispatcher::class),
        $this->createStub(DatabaseManager::class),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    ));

    expect(fn () => $client->getRawMessage(9, '18f9b4a2c1e5d6f7'))
        ->toThrow(RuntimeException::class, 'no OAuth credentials persisted for inbox 9');
});

// The whole incremental scan reads its message ids out of this payload. Left
// unread it returns nothing to fetch on every tick, while the cursor still
// advances — a Gmail inbox that silently never imports a receipt again.
it('unpacks the messagesAdded records the incremental scan fetches from', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'historyId' => '9100',
            'history' => [
                [
                    'id' => '9001',
                    'messagesAdded' => [
                        ['message' => ['id' => 'm-added-1', 'threadId' => 't1']],
                        ['message' => ['id' => 'm-added-2', 'threadId' => 't2']],
                    ],
                ],
            ],
        ]),
    ]);

    $page = $client->listHistory(1, '9000');

    expect($page['historyId'])->toBe('9100')
        ->and($page['history'])->toHaveCount(1)
        ->and($page['history'][0]['messagesAdded'][0]['message']['id'])->toBe('m-added-1')
        ->and($page['history'][0]['messagesAdded'][1]['message']['id'])->toBe('m-added-2');
});

// The walk stops at HISTORY_PAGE_CAP pages. With records consumed, the last
// one is the only watermark the next tick can resume from without loss.
it('stops at the history page cap and resumes from the last record it consumed', function (): void {
    $pages = [];
    for ($i = 1; $i <= 26; $i++) {
        $pages[] = gmailJson([
            'historyId' => '9100',
            'nextPageToken' => 'history-page-'.($i + 1),
            'history' => [
                ['id' => (string) (9000 + $i), 'messagesAdded' => [['message' => ['id' => 'm-'.$i, 'threadId' => 't'.$i]]]],
            ],
        ]);
    }
    $client = ($this->makeClient)($pages);

    $page = $client->listHistory(1, '9000');

    expect($page['history'])->toHaveCount(25)
        ->and($page['historyId'])->toBe('9025');
});

// Gmail answers a nextPageToken with `history` absent whenever
// historyTypes=messageAdded matched nothing on that page. Twenty-five of those
// left no record id to resume from, so the cursor never moved and every later
// tick burned the same twenty-five calls on the same pages.
it('advances to the mailbox historyId when a capped walk consumed no records at all', function (): void {
    $pages = [];
    for ($i = 1; $i <= 26; $i++) {
        $pages[] = gmailJson([
            'historyId' => '9100',
            'nextPageToken' => 'history-page-'.($i + 1),
        ]);
    }
    $client = ($this->makeClient)($pages);

    $page = $client->listHistory(1, '9000');

    expect($page['history'])->toBe([])
        ->and($page['historyId'])->toBe('9100');
});

it('walks every history page before reporting the mailbox historyId', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'historyId' => '9100',
            'nextPageToken' => 'history-page-2',
            'history' => [
                ['id' => '9001', 'messagesAdded' => [['message' => ['id' => 'm-page-1', 'threadId' => 't1']]]],
            ],
        ]),
        gmailJson([
            'historyId' => '9100',
            'history' => [
                ['id' => '9002', 'messagesAdded' => [['message' => ['id' => 'm-page-2', 'threadId' => 't2']]]],
            ],
        ]),
    ]);

    $page = $client->listHistory(1, '9000');

    expect($page['history'])->toHaveCount(2)
        ->and($page['history'][1]['messagesAdded'][0]['message']['id'])->toBe('m-page-2')
        ->and($page['historyId'])->toBe('9100');
});

// A record with nothing fetchable in it still moves the mailbox on, and the
// cursor has to move with it or the next tick re-reads the same page forever.
it('reports the advanced historyId for a history page holding no fetchable message', function (): void {
    $client = ($this->makeClient)([
        gmailJson([
            'historyId' => '9200',
            'history' => [
                ['id' => '9001', 'labelsAdded' => [['message' => ['id' => 'm-labelled']]]],
            ],
        ]),
    ]);

    $page = $client->listHistory(1, '9000');

    expect($page['historyId'])->toBe('9200')
        ->and($page['history'][0]['messagesAdded'])->toBe([]);
});

// Gmail returns a 404 once the stored historyId has aged out of the mailbox's
// ~7-day window; the caller falls back to a date-bounded walk on this sentinel.
it('raises the cursor-expiry sentinel when the stored historyId is no longer known', function (): void {
    $client = ($this->makeClient)([
        new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['code' => 404, 'message' => 'Requested entity was not found.'],
        ])),
    ]);

    expect(fn () => $client->listHistory(1, '1'))
        ->toThrow(CursorExpiredException::class);
});

// A message deleted between the history read and the fetch is permanent for
// that id, and the scan has to tell it apart from a transport fault to skip it
// instead of stalling the cursor behind it.
it('raises a dedicated unavailable sentinel when the message is gone from the mailbox', function (): void {
    $client = ($this->makeClient)([
        new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['code' => 404, 'message' => 'Requested entity was not found.'],
        ])),
    ]);

    expect(fn () => $client->getRawMessage(1, 'm-gone'))
        ->toThrow(MessageUnavailableException::class, 'no longer available on inbox 1');
});

// Google's newer error shape drops the legacy error.errors[] array entirely
// and reports the condition under error.status. Reading only errors[0].reason
// left a genuine 429 falling through as a transport failure, so the inbox
// went to `error` and never rode the rate-limit backoff.
it('maps a throttling response carrying only the newer error shape', function (): void {
    $client = ($this->makeClient)([
        new Response(429, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => [
                'code' => 429,
                'message' => 'Quota exceeded for quota metric.',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ])),
    ]);

    expect(fn () => $client->listSenderMessages(1, ['billing@shop.example'], null))
        ->toThrow(RateLimitedException::class);
});

it('still lets an unrelated Google failure through as itself', function (): void {
    $client = ($this->makeClient)([
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['code' => 500, 'message' => 'Backend error', 'status' => 'INTERNAL'],
        ])),
    ]);

    expect(fn () => $client->listSenderMessages(1, ['billing@shop.example'], null))
        ->not->toThrow(RateLimitedException::class);
});

// Gmail rejects a q= past its own length limit, and the exclude list grows by
// one entry per promoted or dismissed sender for the life of the install.
// Unbounded, discovery eventually 400s on every call — and the excludes are
// re-applied client-side anyway, so dropping the overflow costs nothing.
it('bounds the discovery query however long the exclude list grows', function (): void {
    $excludes = [];
    for ($i = 0; $i < 500; $i++) {
        $excludes[] = 'a-fairly-long-sender-address-'.$i.'@some-merchant-domain.example';
    }

    $client = ($this->makeRecordingClient)([
        gmailJson(['messages' => [], 'resultSizeEstimate' => 0]),
    ]);

    $client->listDiscoveryCandidates(1, ['invoice', 'receipt'], $excludes);

    $sent = ($this->recorded)()[0];
    parse_str((string) parse_url($sent, PHP_URL_QUERY), $query);

    expect(strlen((string) ($query['q'] ?? '')))->toBeLessThanOrEqual(1800)
        ->and((string) ($query['q'] ?? ''))->toContain('subject:("invoice" OR "receipt")')
        ->and((string) ($query['q'] ?? ''))->toContain('-from:(');
});

it('keeps the whole exclude list when it comfortably fits', function (): void {
    $client = ($this->makeRecordingClient)([
        gmailJson(['messages' => [], 'resultSizeEstimate' => 0]),
    ]);

    $client->listDiscoveryCandidates(1, ['invoice'], ['paypal.com', '@ics.nl']);

    $sent = ($this->recorded)()[0];
    parse_str((string) parse_url($sent, PHP_URL_QUERY), $query);

    expect((string) ($query['q'] ?? ''))->toBe('subject:("invoice") -from:(paypal.com OR @ics.nl)');
});
