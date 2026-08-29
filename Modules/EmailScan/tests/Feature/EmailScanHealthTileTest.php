<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Shell\Internal\Http\Livewire\Dashboard;

function ehtUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ehtSeedInbox(
    User $owner,
    string $provider,
    string $email,
    string $status = 'idle',
    ?string $lastScanAt = null,
    ?CarbonImmutable $createdAt = null,
): int {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $created = ($createdAt ?? CarbonImmutable::now())->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => $provider,
        'email' => $email,
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $created,
        'updated_at' => $created,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => $status,
        'last_scan_at' => $lastScanAt,
        'retry_attempts' => 0,
        'created_at' => $created,
        'updated_at' => $created,
    ]);

    return $inboxId;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns null when the user has zero connected inboxes', function (): void {
    $user = ehtUser('zero@example.com');
    $this->actingAs($user);

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($glance->emailScanHealth($user))->toBeNull();

    // The component directly rather than the HTTP route: with zero
    // transactions the route redirects to /imports/new before rendering.
    Livewire::test(Dashboard::class)
        ->assertDontSee('Email scan health');
});

it('renders one healthy line for a recently-scanned single inbox', function (): void {
    $user = ehtUser('healthy@example.com');
    $this->actingAs($user);

    ehtSeedInbox(
        owner: $user,
        provider: 'gmail',
        email: 'healthy@example.com',
        status: 'idle',
        lastScanAt: CarbonImmutable::now()->subHours(3)->toDateTimeString(),
    );

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($user);

    expect($tile)->toBeInstanceOf(EmailScanHealthTile::class);
    expect($tile->overallStatus)->toBe('healthy');
    expect($tile->overflowCount)->toBe(0);
    expect($tile->lines)->toHaveCount(1);
    expect($tile->lines[0]->status)->toBe('healthy');
    expect($tile->lines[0]->provider)->toBe('gmail');

    Livewire::test(Dashboard::class)
        ->assertSee('Email scan health')
        ->assertSee('Gmail: last scanned 3 hours ago')
        ->assertSee('bg-emerald-700');
});

it('flips overall status to reauth when any inbox needs reconnect', function (): void {
    $user = ehtUser('reauth@example.com');
    $this->actingAs($user);

    ehtSeedInbox(
        owner: $user,
        provider: 'gmail',
        email: 'good@example.com',
        status: 'idle',
        lastScanAt: CarbonImmutable::now()->subHours(2)->toDateTimeString(),
        createdAt: CarbonImmutable::now()->subDays(2),
    );
    ehtSeedInbox(
        owner: $user,
        provider: 'microsoft',
        email: 'broken@example.com',
        status: 'needs_reauth',
        lastScanAt: CarbonImmutable::now()->subHours(5)->toDateTimeString(),
        createdAt: CarbonImmutable::now()->subDay(),
    );

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($user);

    expect($tile)->not->toBeNull();
    expect($tile->overallStatus)->toBe('reauth');
    expect($tile->lines)->toHaveCount(2);
    $reauthLine = collect($tile->lines)->first(fn ($l) => $l->status === 'reauth');
    expect($reauthLine)->not->toBeNull();
    expect($reauthLine->provider)->toBe('microsoft');

    Livewire::test(Dashboard::class)
        ->assertSee('Microsoft 365: needs reconnect')
        ->assertSee('bg-rose-600');
});

it('marks an inbox as stale when last_scan_at is older than 24 hours', function (): void {
    $user = ehtUser('stale@example.com');
    $this->actingAs($user);

    ehtSeedInbox(
        owner: $user,
        provider: 'gmail',
        email: 'stale@example.com',
        status: 'idle',
        // 25 hours back — past the STALE_THRESHOLD_SECONDS = 86400 wall.
        lastScanAt: CarbonImmutable::now()->subHours(25)->toDateTimeString(),
    );

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($user);

    expect($tile)->not->toBeNull();
    expect($tile->overallStatus)->toBe('stale');
    expect($tile->lines[0]->status)->toBe('stale');

    Livewire::test(Dashboard::class)
        ->assertSee('bg-amber-700');
});

it('marks a never-scanned inbox as stale and renders the "not scanned yet" copy', function (): void {
    $user = ehtUser('never@example.com');
    $this->actingAs($user);

    ehtSeedInbox(
        owner: $user,
        provider: 'microsoft',
        email: 'never@example.com',
        status: 'idle',
        lastScanAt: null,
    );

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($user);

    expect($tile)->not->toBeNull();
    expect($tile->overallStatus)->toBe('stale');
    expect($tile->lines[0]->status)->toBe('stale');
    expect($tile->lines[0]->lastScanAt)->toBeNull();

    Livewire::test(Dashboard::class)
        ->assertSee('Microsoft 365: not scanned yet');
});

it('caps the tile at three lines and reports the overflow count', function (): void {
    $user = ehtUser('overflow@example.com');
    $this->actingAs($user);

    for ($i = 1; $i <= 5; $i++) {
        ehtSeedInbox(
            owner: $user,
            provider: 'gmail',
            email: "user{$i}@example.com",
            status: 'idle',
            lastScanAt: CarbonImmutable::now()->subHours(2)->toDateTimeString(),
            createdAt: CarbonImmutable::now()->subDays(10 - $i),
        );
    }

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($user);

    expect($tile)->not->toBeNull();
    expect($tile->lines)->toHaveCount(3);
    expect($tile->overflowCount)->toBe(2);

    Livewire::test(Dashboard::class)
        ->assertSee('+2 more');
});

it('cross-user isolation: another user\'s inboxes never appear on this user\'s tile', function (): void {
    $userA = ehtUser('a@example.com');
    $userB = ehtUser('b@example.com');

    ehtSeedInbox(
        owner: $userB,
        provider: 'gmail',
        email: 'b@example.com',
        status: 'needs_reauth',
        lastScanAt: null,
    );

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($glance->emailScanHealth($userA))->toBeNull();

    $tileB = $glance->emailScanHealth($userB);
    expect($tileB)->not->toBeNull();
    expect($tileB->overallStatus)->toBe('reauth');
});
