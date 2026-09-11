<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\PeerRowAliases;

uses(RefreshDatabase::class);

// Counterparties take the next autoincrement on whichever device first sees the
// merchant, so the two devices disagree about the number from the first import
// apart. The alias table records the disagreement; translate() is what spends it.

function merchantAliasUser(): User
{
    return User::query()->create([
        'username' => 'cpid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function merchantAliasCounterparty(int $userId, string $slug): int
{
    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'display_name' => ucfirst($slug), 'slug' => $slug,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function merchantAliasRemember(int $userId, string $table, int $remoteId, int $localId): void
{
    DB::table('op_log_row_aliases')->insert([
        'user_id' => $userId, 'table_name' => $table, 'device_id' => 'peer-device',
        'remote_id' => (string) $remoteId, 'local_id' => (string) $localId,
        'created_at' => '2026-07-01 00:00:00',
    ]);
}

it('rewrites the merchant a peer names to the one this device calls by another number', function (): void {
    $user = merchantAliasUser();
    $local = merchantAliasCounterparty((int) $user->id, 'albert-heijn');
    merchantAliasRemember((int) $user->id, 'counterparties', 5, $local);

    $translated = app(PeerRowAliases::class)
        ->translate('transactions', 'peer-device', ['counterparty_id' => 5], (int) $user->id);

    expect($translated['counterparty_id'])->toBe($local);
});

it('rewrites the series a scenario mutation names', function (): void {
    $user = merchantAliasUser();

    $localSeries = (int) DB::table('recurring_series')->insertGetId([
        'user_id' => $user->id, 'direction' => 'expense', 'detected_name' => 'Spotify',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR', 'variance_tolerance_percent' => 10,
        'next_expected_confidence_low' => 0, 'cluster_key' => 'spotify',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    merchantAliasRemember((int) $user->id, 'recurring_series', 11, $localSeries);

    $translated = app(PeerRowAliases::class)
        ->translate('forecast_scenario_mutations', 'peer-device', ['target_series_id' => 11], (int) $user->id);

    expect($translated['target_series_id'])->toBe($localSeries);
});

it('leaves an id the two devices already agree on where it is', function (): void {
    $user = merchantAliasUser();
    $local = merchantAliasCounterparty((int) $user->id, 'jumbo');

    // No alias row: the peer minted the same number, which is the ordinary case
    // and the one a translation that rewrote everything would break.
    $translated = app(PeerRowAliases::class)
        ->translate('transactions', 'peer-device', ['counterparty_id' => $local], (int) $user->id);

    expect($translated['counterparty_id'])->toBe($local);
});

it('leaves a payload that names no merchant alone', function (): void {
    $user = merchantAliasUser();
    merchantAliasRemember((int) $user->id, 'counterparties', 5, merchantAliasCounterparty((int) $user->id, 'lidl'));

    $translated = app(PeerRowAliases::class)
        ->translate('transactions', 'peer-device', ['counterparty_id' => null, 'note' => 'x'], (int) $user->id);

    expect($translated['counterparty_id'])->toBeNull()
        ->and($translated['note'])->toBe('x');
});
