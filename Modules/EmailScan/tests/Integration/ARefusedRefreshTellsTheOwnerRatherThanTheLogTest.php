<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailInboxResources;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

// Both clients refresh before every call, and the refusal that comes back from
// a revoked grant is the one failure a reader can act on. It has to arrive as
// InboxTokenFailed carrying the OWNING user, because that is the id the
// re-consent banner is keyed on — a scan that only logs it shows nothing.

/**
 * @return object{secrets: OAuthSecretsRepository, events: EventsDispatcher, dispatched: Closure}
 */
function refusedRefreshRig(): object
{
    $secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return new InboxCredentials(
                inboxId: $inboxId,
                provider: 'microsoft',
                refreshToken: 'revoked-refresh-token',
                scope: 'Mail.Read offline_access User.Read',
                expiresAt: null,
                accessToken: null,
            );
        }

        public function rotateRefreshToken(int $inboxId, string $newRefreshToken, ?string $newAccessToken, ?DateTimeImmutable $expiresAt): void {}
    };

    $captured = [];
    $events = new class($captured) implements EventsDispatcher
    {
        /** @param list<object> $captured */
        public function __construct(public array &$captured) {}

        public function listen($events, $listener = null): void {}

        public function hasListeners($eventName): bool
        {
            return false;
        }

        public function subscribe($subscriber): void {}

        public function until($event, $payload = []): mixed
        {
            return null;
        }

        public function dispatch($event, $payload = [], $halt = false): mixed
        {
            if (is_object($event)) {
                $this->captured[] = $event;
            }

            return null;
        }

        public function push($event, $payload = []): void {}

        public function flush($event): void {}

        public function forget($event): void {}

        public function forgetPushed(): void {}
    };

    return (object) [
        'secrets' => $secrets,
        'events' => $events,
        'dispatched' => static fn (): array => $events->captured,
    ];
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->owner = User::query()->create([
        'username' => 'grant-owner',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $now = CarbonImmutable::parse('2026-05-20 09:00:00')->toDateTimeString();
    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->owner->id,
        'provider' => 'microsoft',
        'email' => 'owner@contoso.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-20 10:00:00');
        }
    };
});

it('turns a Graph refresh Microsoft refuses into a re-consent the owner is told about', function (): void {
    $rig = refusedRefreshRig();

    $oauth = new class extends MicrosoftOAuthProvider
    {
        public function __construct() {}

        public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
        {
            throw new InvalidGrantException('Microsoft OAuth refresh rejected with invalid_grant — reconnect required.');
        }
    };

    $client = new GraphApiClient(
        $rig->secrets,
        $oauth,
        $this->clock,
        $rig->events,
        $this->db,
        new GraphErrorMapper($this->clock),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    );

    $refusal = null;
    try {
        $client->deltaPage($this->inboxId, null);
    } catch (ReconsentRequiredException $e) {
        $refusal = $e;
    }

    $dispatched = ($rig->dispatched)();

    expect($refusal)->not->toBeNull()
        ->and($refusal->inboxId)->toBe($this->inboxId)
        ->and($refusal->userId)->toBe($this->owner->id)
        ->and($refusal->provider)->toBe('microsoft')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0])->toBeInstanceOf(InboxTokenFailed::class)
        ->and($dispatched[0]->userId)->toBe($this->owner->id)
        ->and($dispatched[0]->provider)->toBe('microsoft');
});

it('turns a Gmail refresh Google refuses into a re-consent the owner is told about', function (): void {
    $rig = refusedRefreshRig();

    $oauth = new class extends GoogleOAuthProvider
    {
        public function __construct() {}

        public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
        {
            throw new InvalidGrantException('Google OAuth refresh rejected with invalid_grant — reconnect required.');
        }
    };

    $client = new GmailApiClient(new GmailInboxResources(
        $rig->secrets,
        $oauth,
        $this->clock,
        $rig->events,
        $this->db,
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    ));

    $refusal = null;
    try {
        $client->currentHistoryId($this->inboxId);
    } catch (ReconsentRequiredException $e) {
        $refusal = $e;
    }

    $dispatched = ($rig->dispatched)();

    expect($refusal)->not->toBeNull()
        ->and($refusal->userId)->toBe($this->owner->id)
        ->and($refusal->provider)->toBe('gmail')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->userId)->toBe($this->owner->id);
});

// The inbox can be disconnected between a scan being queued and its refresh
// being refused. Recovery still has to complete, so the lookup answers 0
// rather than replacing a consent failure with an unhandled one.
it('still completes the refusal when the inbox was disconnected mid-scan', function (): void {
    $rig = refusedRefreshRig();
    $this->db->connection()->table('inboxes')->where('id', $this->inboxId)->delete();

    $oauth = new class extends MicrosoftOAuthProvider
    {
        public function __construct() {}

        public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
        {
            throw new InvalidGrantException('revoked');
        }
    };

    $client = new GraphApiClient(
        $rig->secrets,
        $oauth,
        $this->clock,
        $rig->events,
        $this->db,
        new GraphErrorMapper($this->clock),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]),
    );

    expect(fn () => $client->deltaPage($this->inboxId, null))
        ->toThrow(ReconsentRequiredException::class)
        ->and(($rig->dispatched)()[0]->userId)->toBe(0);
});
