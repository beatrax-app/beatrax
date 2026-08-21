<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailRawDecodeException;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
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

    $this->makeClient = function (array $responses): GmailApiClient {
        return new GmailApiClient(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
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

    $client = new GmailApiClient(
        $secrets,
        $this->oauth,
        $this->clock,
        $this->createStub(EventsDispatcher::class),
        $this->createStub(DatabaseManager::class),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    );

    expect(fn () => $client->getRawMessage(9, '18f9b4a2c1e5d6f7'))
        ->toThrow(RuntimeException::class, 'no OAuth credentials persisted for inbox 9');
});
