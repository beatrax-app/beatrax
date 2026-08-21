<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Notifications\Database\Seeders\Demo\DemoNotificationsSeeder;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Recurring\Models\RecurringSeries;

uses(RefreshDatabase::class);

// The fixture stands up only what DemoNotificationsSeeder actually references
// rather than running the whole demo:seed pipeline, so every entity the seeder
// touches is under this file's control.

/**
 * @return array{id: int, users: array<string, User>}
 */
function dnsPrimaryUser(): array
{
    $user = User::query()->create([
        'username' => 'demo-1@beatrax.local',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    return ['id' => (int) $user->id, 'users' => ['demo-1@beatrax.local' => $user]];
}

function dnsSeries(User $user, string $clusterKey, int $amountMinor): int
{
    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $clusterKey,
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'latest_fx_rate_used' => null,
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 25,
        'latest_funding_chain_link_id' => null,
        'snoozed_until' => null,
        'next_expected_at' => CarbonImmutable::today()->addMonthNoOverflow(),
        'next_expected_confidence_low' => false,
        'cluster_key' => $clusterKey,
        'cluster_counterparty_key' => $clusterKey,
    ]);

    return (int) $series->id;
}

function dnsCategory(string $slug, string $name): void
{
    Category::query()->firstOrCreate(
        ['user_id' => null, 'slug' => $slug],
        ['name' => $name, 'kind' => 'expense', 'display_order' => 1],
    );
}

function dnsAccount(User $user): int
{
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN Demo',
        'slug' => 'asn-demo-1',
        'kind' => 'bank',
        'iban' => 'NL00DNS0000000001',
        'default_currency' => 'EUR',
    ]);

    return (int) $account->id;
}

// The transaction -> occurrence -> alert chain a real DriftEvaluator run leaves
// behind; the seeder reads all three links.
function dnsOpenDriftAlert(DatabaseManager $db, User $user, int $accountId, int $seriesId, int $baselineMinor, int $latestMinor): void
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/dns-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'dns-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var Transaction $tx */
    $tx = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::today()->toDateString(),
        'booked_at' => CarbonImmutable::today()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::today()->toDateString(),
        'amount_minor' => -$latestMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$latestMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'DNS Merchant '.$seriesId,
        'counterparty_normalized' => 'dns merchant '.$seriesId,
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $seriesId,
        'fingerprint' => str_pad('dns'.$seriesId, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $occurrenceId = (int) $db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $tx->id,
        'observed_at' => CarbonImmutable::today()->toDateString(),
        'observed_amount_minor' => $latestMinor,
        'observed_currency' => 'EUR',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    DriftAlert::query()->create([
        'user_id' => $user->id,
        'recurring_series_id' => $seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -$baselineMinor,
        'latest_amount_minor' => -$latestMinor,
        'currency' => 'EUR',
        'delta_minor' => $baselineMinor - $latestMinor,
        'annualized_impact_minor' => ($baselineMinor - $latestMinor) * 12,
        'threshold_percent_used' => 10,
        'threshold_source' => 'user_default',
        'latest_occurrence_id' => $occurrenceId,
        'snoozed_until' => null,
        'detected_at' => CarbonImmutable::now(),
        'actioned_at' => null,
    ]);
}

/**
 * @return array<string, User>
 */
function dnsSeedFixtures(): array
{
    $primary = dnsPrimaryUser();
    $user = $primary['users']['demo-1@beatrax.local'];

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $kpnId = dnsSeries($user, 'demo:kpn:monthly:4500', 4500);
    $sportCityId = dnsSeries($user, 'demo:sport-city:monthly:2500', 2500);
    $spotifyId = dnsSeries($user, 'demo:spotify:monthly:1099', 1099);
    dnsSeries($user, 'demo:netflix:monthly:1499', 1499);

    dnsCategory('groceries', 'Groceries');
    dnsCategory('eating-out', 'Eating out');

    $accountId = dnsAccount($user);

    dnsOpenDriftAlert($db, $user, $accountId, $spotifyId, 1099, 1499);
    dnsOpenDriftAlert($db, $user, $accountId, $sportCityId, 2500, 2750);

    unset($kpnId);

    return $primary['users'];
}

/**
 * @return array<int, string>
 */
function dnsTriggerTypes(int $userId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->distinct()
        ->pluck('trigger_type')
        ->map(static fn (mixed $v): string => (string) $v)
        ->sort()
        ->values()
        ->all();
}

function dnsTotalCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')->where('user_id', $userId)->count();
}

function dnsUnreadCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->whereNull('read_at')
        ->whereNull('dismissed_at')
        ->count();
}

function dnsReadCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->whereNotNull('read_at')
        ->count();
}

function dnsDismissedCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->whereNotNull('dismissed_at')
        ->count();
}

function dnsResolvedCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('state', 'resolved')
        ->count();
}

// True when at least one reminder row's deep link resolves as disabled.
function dnsHasDeadLink(User $user): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var DeepLinkResolver $resolver */
    $resolver = app(DeepLinkResolver::class);

    $rows = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER)
        ->get(['id', 'title', 'body', 'trigger_type', 'read_at', 'dismissed_at', 'state', 'created_at']);

    foreach ($rows as $row) {
        $dto = new NotificationDto(
            id: (string) $row->id,
            triggerType: (string) $row->trigger_type,
            title: (string) $row->title,
            body: (string) $row->body,
            readAt: $row->read_at !== null ? CarbonImmutable::parse((string) $row->read_at) : null,
            dismissedAt: $row->dismissed_at !== null ? CarbonImmutable::parse((string) $row->dismissed_at) : null,
            state: (string) $row->state,
            createdAt: CarbonImmutable::parse((string) $row->created_at),
            deepLinkUrl: null,
            deepLinkDisabled: false,
            targetKind: null,
            glyph: '?',
            typeWord: 'Reminder',
        );

        $resolved = $resolver->resolve($dto, $user);
        if ($resolved->deepLinkDisabled) {
            return true;
        }
    }

    return false;
}

it('produces rows covering all 8 trigger types', function (): void {
    $users = dnsSeedFixtures();
    $user = $users['demo-1@beatrax.local'];

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users);

    expect(dnsTriggerTypes($user->id))->toBe([
        DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE,
        DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
        DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
        DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST,
        DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
        DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT,
    ]);
});

it('produces every interesting state: unread, read, dismissed, resolved, and a dead link', function (): void {
    $users = dnsSeedFixtures();
    $user = $users['demo-1@beatrax.local'];

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users);

    expect(dnsUnreadCount($user->id))->toBeGreaterThan(0);
    expect(dnsReadCount($user->id))->toBeGreaterThan(0);
    expect(dnsDismissedCount($user->id))->toBeGreaterThan(0);
    expect(dnsResolvedCount($user->id))->toBeGreaterThan(0);
    expect(dnsHasDeadLink($user))->toBeTrue();
});

it('keeps the unread count strictly between 0 and the total, so the nav badge shows a real small number', function (): void {
    $users = dnsSeedFixtures();
    $user = $users['demo-1@beatrax.local'];

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users);

    $total = dnsTotalCount($user->id);
    $unread = dnsUnreadCount($user->id);

    expect($unread)->toBeGreaterThan(0);
    expect($unread)->toBeLessThan($total);
});

it('fires ZERO OS notifications during the seed run', function (): void {
    $users = dnsSeedFixtures();

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users);

    Http::assertNothingSent();
});

it('is idempotent: running the seeder twice yields the same row count', function (): void {
    $users = dnsSeedFixtures();
    $user = $users['demo-1@beatrax.local'];

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users);
    $firstCount = dnsTotalCount($user->id);

    app(DemoNotificationsSeeder::class)->run($users);
    $secondCount = dnsTotalCount($user->id);

    expect($secondCount)->toBe($firstCount);
    Http::assertNothingSent();
});

it('reaches 50+ rows when the volume knob is used', function (): void {
    $users = dnsSeedFixtures();
    $user = $users['demo-1@beatrax.local'];

    Http::fake();
    app(DemoNotificationsSeeder::class)->run($users, 45);

    expect(dnsTotalCount($user->id))->toBeGreaterThanOrEqual(50);
    Http::assertNothingSent();
});
