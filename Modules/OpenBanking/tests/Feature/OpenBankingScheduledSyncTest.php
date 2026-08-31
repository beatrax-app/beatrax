<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;

uses(RefreshDatabase::class);

// The off/expired no-op is enforced at the enumeration query, not only by the
// job's re-check on pickup, so these drive the registered CallbackEvent itself.

function ossFindEvent(Schedule $schedule, string $description): ?ScheduledEvent
{
    foreach ($schedule->events() as $event) {
        /** @var ScheduledEvent $event */
        if ($event->description === $description) {
            return $event;
        }
    }

    return null;
}

function ossUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ossSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// 06:00 sat ahead of the FX refresh and the notification pass, which it still
// does now that all three are daily and this one is defined after FX. The hour
// itself had to go: the phone's background runner takes only intervals.

it('registers the open-banking.daily-sync entry daily, running open-banking:sync-due', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = ossFindEvent($schedule, 'open-banking.daily-sync');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "open-banking.daily-sync".');
    expect($event->expression)->toBe('0 0 * * *');
    expect((string) $event->command)->toContain('open-banking:sync-due');
    expect($event->mutexName())->not->toBe('');
});

it('dispatches SyncOpenBankingAccountJob for an enabled connection with a non-expired consent', function (): void {
    Bus::fake();

    $user = ossUser('oss-eligible');
    $connectionId = ossSeedConnection($user);

    Artisan::call('open-banking:sync-due');

    Bus::assertDispatched(
        SyncOpenBankingAccountJob::class,
        fn (SyncOpenBankingAccountJob $job): bool => $job->connectionId === $connectionId
    );
});

it('does NOT dispatch for a connection that is disabled', function (): void {
    Bus::fake();

    $user = ossUser('oss-disabled');
    ossSeedConnection($user, ['enabled' => false]);

    Artisan::call('open-banking:sync-due');

    Bus::assertNotDispatched(SyncOpenBankingAccountJob::class);
});

it('does NOT dispatch for a connection whose consent has expired', function (): void {
    Bus::fake();

    $user = ossUser('oss-expired');
    ossSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::parse('2026-01-01 00:00:00')->toDateTimeString(),
    ]);

    Artisan::call('open-banking:sync-due');

    Bus::assertNotDispatched(SyncOpenBankingAccountJob::class);
});

it('does NOT dispatch for a connection with a null consent_expires_at', function (): void {
    Bus::fake();

    $user = ossUser('oss-null-consent');
    ossSeedConnection($user, ['consent_expires_at' => null]);

    Artisan::call('open-banking:sync-due');

    Bus::assertNotDispatched(SyncOpenBankingAccountJob::class);
});
