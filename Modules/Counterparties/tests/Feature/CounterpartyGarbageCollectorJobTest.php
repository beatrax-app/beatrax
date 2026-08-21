<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;

uses(RefreshDatabase::class);

function makeGcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function makeGcCounterparty(int $userId, string $slug, string $displayName, ?string $merchantName = null, string $type = 'merchant'): int
{
    $now = now()->toDateTimeString();
    $id = DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $displayName,
        'iban' => null,
        'merchant_name' => $merchantName,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $id;
}

it('Test 1 — prunes a Counterparty with zero recent transactions AND zero merchant_alias entries', function (): void {
    $user = makeGcUser('gc-test-1');
    $orphanId = makeGcCounterparty($user->id, 'stale-merchant', 'Stale Merchant');

    expect(DB::table('counterparties')->where('id', $orphanId)->count())->toBe(1);

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(DB::table('counterparties')->where('id', $orphanId)->count())->toBe(0);
});

it('Test 2 — does NOT prune a Counterparty with a transaction in the last 365 days', function (): void {
    $user = makeGcUser('gc-test-2');
    $aliveId = makeGcCounterparty($user->id, 'active-merchant', 'Active Merchant');

    // transactions.import_run_id is `constrained()`, so the run has to exist
    // before the transaction that pins the counterparty can be inserted.
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'gc-test-2-account',
        'slug' => 'gc-test-2-account',
        'kind' => 'bank',
        'iban' => 'NL41BANK0000000002',
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => 'storage/app/imports/gc-test-2.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => now()->toDateTimeString(),
        'confirmed_at' => now()->toDateTimeString(),
        'status' => 'confirmed',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateTimeString(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Active Merchant',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'active merchant',
        'normalization_version' => 1,
        'description' => 'recent activity',
        'category_id' => null,
        'counterparty_id' => $aliveId,
        'auto_category_provenance' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $runId,
        'source_row_index' => 0,
        'source_ref' => null,
        'raw_payload' => null,
        'payment_type' => 'unknown',
        'status' => 'cleared',
        'fingerprint' => str_repeat('a', 64),
        'fingerprint_version' => 3,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(DB::table('counterparties')->where('id', $aliveId)->count())->toBe(1);
});

it('Test 3 — does NOT prune a Counterparty whose merchant_name is referenced by a merchant_alias row (alias preserves the link)', function (): void {
    $user = makeGcUser('gc-test-3');
    $aliasBackedId = makeGcCounterparty(
        userId: $user->id,
        slug: 'spotify',
        displayName: 'Spotify',
        merchantName: 'Spotify',
    );

    // The GC join keys friendly_name against merchant_name, within one user.
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'SPOTIFY AB',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(DB::table('counterparties')->where('id', $aliasBackedId)->count())->toBe(1);
});

it('Test 4 — declares ShouldBeUniqueUntilProcessing with uniqueId=userId, uniqueFor=3600s, uniqueVia=LockStore::forUniqueJobs', function (): void {
    $job = new CounterpartyGarbageCollectorJob(userId: 42);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    expect($job->uniqueId())->toBe('42');
    expect($job->uniqueFor())->toBe(3600);
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('Test 5 — registers a daily counterparties.gc schedule entry at 04:00 Europe/Amsterdam', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $matched = null;
    foreach ($schedule->events() as $event) {
        /** @var ScheduledEvent $event */
        if ($event->description === 'counterparties.gc') {
            $matched = $event;
            break;
        }
    }

    expect($matched)->not->toBeNull('Expected a registered schedule entry with description "counterparties.gc".');
    expect($matched->expression)->toBe('0 4 * * *');
    expect($matched->timezone)->toBe('Europe/Amsterdam');
    expect($matched->mutexName())->not->toBe('');
});
