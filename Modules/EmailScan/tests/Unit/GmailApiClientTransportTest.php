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
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/**
 * GmailApiClient driven through a mocked transport.
 *
 * Unlike the Graph client this one talks through the Google SDK, so the seam
 * is the SDK's own HTTP client rather than a Guzzle instance the class builds
 * itself. Injecting one still reaches the same place: the query this codebase
 * composes, and the decoding it does on the way back.
 */
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

// An inbox row whose OAuth credentials were never persisted, or were revoked
// and cleared. Reaching Google with no token would come back as a 401 the
// caller could mistake for an expired grant, so it is refused before the
// service is built.
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
