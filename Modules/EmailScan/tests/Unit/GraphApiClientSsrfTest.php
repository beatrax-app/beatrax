<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use PHPUnit\Framework\MockObject\MockObject;

// The delta and pagination contract follows @odata.nextLink / @odata.deltaLink
// verbatim, so a response substituting an attacker's host would carry a valid
// Mail.Read bearer straight to them. The allow-list has to reject the URL
// before any Authorization header is attached, not after.

beforeEach(function (): void {
    $expiresAt = (new DateTimeImmutable)->setTimestamp(time() + 3600);

    // A valid token, so the request reaches assertAllowedUrl instead of
    // tripping over a missing credential first.
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

    $this->client = new GraphApiClient($this->secrets, $this->oauth, $this->clock, $events, $db, new GraphErrorMapper($this->clock));
});

it('rejects a deltaPage follow-up against an attacker-controlled host before any bearer token is attached', function (): void {
    expect(fn () => $this->client->deltaPage(
        inboxId: 1,
        deltaLink: 'https://attacker.example.com/v1.0/me/messages/delta?$deltatoken=evil',
    ))->toThrow(
        RuntimeException::class,
        'refusing to send bearer token to non-Graph host: attacker.example.com',
    );
});

it('rejects a listSenderMessagesPaged follow-up against an attacker-controlled host', function (): void {
    // The nextLink a malformed first page would have handed back.
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
