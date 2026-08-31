<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Recurring\Internal\Detection\ClusterKeyComposer;
use Modules\Recurring\Public\Enums\SeriesCadence;

// `cluster_key` is composed over the counterparty key and is half of
// UNIQUE(user_id, direction, cluster_key, latest_currency). The enable-time
// sweep rewrites it and so does the detector, in two different modules that
// cannot share a helper — so a swept row and a freshly detected one have to
// agree byte for byte or the detector inserts a duplicate series.
function cksvUser(): User
{
    return User::query()->create([
        'username' => 'cksv-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 12,
    ]);
}

function cksvSeries(User $user, string $direction, string $counterpartyKey): void
{
    app(DatabaseManager::class)->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => 'Employer BV',
        'state' => 'approved',
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => 250000,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $direction.'::'.strtolower($counterpartyKey).'::eur::monthly',
        'cluster_counterparty_key' => $counterpartyKey,
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

function cksvSweep(User $user): void
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);
}

/**
 * @return array{cluster_key: string, cluster_counterparty_key: string}
 */
function cksvRow(User $user): array
{
    $row = app(DatabaseManager::class)->connection()
        ->table('recurring_series')
        ->where('user_id', $user->id)
        ->first(['cluster_key', 'cluster_counterparty_key']);

    expect($row)->not->toBeNull();

    return [
        'cluster_key' => (string) $row->cluster_key,
        'cluster_counterparty_key' => (string) $row->cluster_counterparty_key,
    ];
}

// The sweep's input domain is exactly this: a direction, a 64-hex digest, a
// three-letter currency and a cadence enum value. Parity over that domain is
// parity, because the sweep never composes anything else.
it('composes a swept cluster_key exactly as ClusterKeyComposer would', function (string $direction, string $counterpartyKey): void {
    $user = cksvUser();
    cksvSeries($user, $direction, $counterpartyKey);

    cksvSweep($user);

    $row = cksvRow($user);

    expect($row['cluster_key'])->toBe(app(ClusterKeyComposer::class)->compose(
        $direction,
        $row['cluster_counterparty_key'],
        'EUR',
        SeriesCadence::Monthly->value,
    ));
})->with([
    'income payer IBAN' => [Direction::Income->value, 'NL22INGB0006543210'],
    'expense merchant key' => [Direction::Expense->value, 'spotify ab'],
    // CounterpartyKeyBackfill carries its own copy of normalisePart() and that
    // copy is ASCII-only, so these two pin the claim the copy relies on: the
    // sweep keys the counterparty before it composes, and a keyed value is hex.
    'expense merchant key in Cyrillic' => [Direction::Expense->value, 'мосэнерго'],
    'expense merchant key with an ampersand' => [Direction::Expense->value, 'a&b'],
]);

// A digest is 64 characters and normalisePart() caps a part at 60, so the
// composed key carries 240 of its 256 bits. Pinned because the sweep and the
// detector have to truncate identically, not because the truncation is wanted.
it('carries the counterparty digest into cluster_key, truncated the way the composer truncates', function (): void {
    $user = cksvUser();
    cksvSeries($user, Direction::Income->value, 'NL22INGB0006543210');

    cksvSweep($user);

    $row = cksvRow($user);
    $keyed = app(CounterpartyKey::class)->forIban('NL22INGB0006543210', (int) $user->id);

    expect($row['cluster_counterparty_key'])->toBe($keyed);
    expect($row['cluster_key'])->toBe('income::'.substr($keyed, 0, 60).'::eur::monthly');
    expect($row['cluster_key'])->not->toContain('ingb');
});
