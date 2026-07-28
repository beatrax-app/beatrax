<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use PHPUnit\Framework\MockObject\MockObject;

/*
 * SSRF / bearer-token-leak regression test for GraphApiClient.
 *
 * The Graph delta + pagination contract follows @odata.nextLink /
 * @odata.deltaLink URLs verbatim. A hostile / malformed Graph response
 * substituting an attacker-controlled host into the nextLink would,
 * absent the host allow-list, see a valid Mail.Read bearer attached on
 * the next request — exfiltrating the token to the attacker.
 *
 * These tests assert that the allow-list rejects the request BEFORE
 * any bearer header is attached. The OAuth provider is stubbed to
 * succeed at refresh — if the request were allowed through, the test
 * would hit a real HTTP call (not what we want). The expected RuntimeException
 * fires from the assertAllowedUrl guard inside getJson() / getRawMessage().
 */

beforeEach(function (): void {
    $expiresAt = (new DateTimeImmutable)->setTimestamp(time() + 3600);

    // Stub the secrets repository to return a fresh, valid access token
    // so the request reaches assertAllowedUrl rather than tripping over
    // a missing credential.
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

    /** @var EventsDispatcher&MockObject $events */
    $events = $this->createStub(EventsDispatcher::class);
    /** @var DatabaseManager&MockObject $db */
    $db = $this->createStub(DatabaseManager::class);

    $this->client = new GraphApiClient($this->secrets, $this->oauth, $this->clock, $events, $db);
});

it('rejects a deltaPage follow-up against an attacker-controlled host before any bearer token is attached', function (): void {
    // A crafted deltaLink pointing at an attacker host would, without
    // the SSRF guard, see Authorization: Bearer <token> attached on
    // the request. The assertAllowedUrl gate rejects the URL first.
    expect(fn () => $this->client->deltaPage(
        inboxId: 1,
        deltaLink: 'https://attacker.example.com/v1.0/me/messages/delta?$deltatoken=evil',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token to non-Graph host: attacker.example.com',
    );
});

it('rejects a listSenderMessagesPaged follow-up against an attacker-controlled host', function (): void {
    // The nextLink path: the first page returns a malformed
    // @odata.nextLink pointing at attacker.example.com. The next call
    // is refused before any bearer token leaves the box.
    expect(fn () => $this->client->listSenderMessagesPaged(
        inboxId: 1,
        senderPatterns: ['paypal.com'],
        windowStart: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        nextLink: 'https://attacker.example.com/v1.0/me/messages?$skiptoken=evil',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token to non-Graph host: attacker.example.com',
    );
});

it('rejects a non-HTTPS nextLink even on the Graph host', function (): void {
    expect(fn () => $this->client->deltaPage(
        inboxId: 1,
        deltaLink: 'http://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=evil',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token over non-HTTPS scheme',
    );
});

it('rejects a listDiscoveryCandidatesPaged follow-up against an attacker-controlled host', function (): void {
    expect(fn () => $this->client->listDiscoveryCandidatesPaged(
        inboxId: 1,
        keywords: ['receipt'],
        excludeSenders: [],
        nextLink: 'https://graph.microsoft.com.evil.example/v1.0/me/messages',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token to non-Graph host: graph.microsoft.com.evil.example',
    );
});

it('rejects an unparseable URL', function (): void {
    expect(fn () => $this->client->deltaPage(
        inboxId: 1,
        deltaLink: '://no-scheme-here',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token',
    );
});
