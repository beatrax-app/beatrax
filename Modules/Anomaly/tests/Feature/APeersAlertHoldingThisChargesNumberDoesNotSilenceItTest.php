<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;

uses(RefreshDatabase::class);

// The alert id used to be folded from the charge's own id, and a charge's id is
// a number each device counts for itself. So the peer's alert for ITS
// transaction 18 arrived wearing the number this device had reserved for the
// alert on ITS transaction 18 — a different charge entirely.

// Two things followed on the install this was measured on. The arriving row
// landed against a charge it does not describe, and the charge that was due an
// alert of its own could not be given one, because its number was taken.

// The peer's alert, planted at the number the fold would have produced here.
// Its own transaction is a different charge, which is the whole point.
function peerNumberPlantAlert(DatabaseManager $db, User $user, int $alertId, int $transactionId, int $latestMinor): void
{
    $db->connection()->table('anomaly_alerts')->insert([
        'id' => $alertId,
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['duplicate']),
        'baseline_amount_minor' => null,
        'latest_amount_minor' => $latestMinor,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-06-16 09:00:00',
        'created_at' => '2026-06-16 09:00:00',
        'updated_at' => '2026-06-16 09:00:00',
    ]);
}

function peerNumberOtherChargeOf(DatabaseManager $db, User $user, int $notThisOne): int
{
    /** @var int $id */
    $id = $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('id', '<>', $notThisOne)
        ->orderBy('id')
        ->value('id');

    return $id;
}

function peerNumberRepairMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'Modules/Anomaly/Database/Migrations/2026_09_11_000001_an_alert_that_states_an_amount_the_charge_it_names_does_not_have.php'
    );

    return $migration;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = AnomalyCorpusSeeder::makeUser();
    $this->under = AnomalyCorpusSeeder::seed($db, $this->user, AnomalyCorpusSeeder::load('large-above'));
});

it('opens the alert its own charge is due when a peer alert already holds that number', function (): void {
    $userId = (int) $this->user->id;
    $peerCharge = peerNumberOtherChargeOf($this->db, $this->user, $this->under);

    $folded = DerivedRowId::for('anomaly_alerts', [
        'user_id' => $userId,
        'transaction_id' => $this->under,
    ]);

    peerNumberPlantAlert($this->db, $this->user, $folded, $peerCharge, -2050);

    $this->app->make(AnomalyEvaluator::class)->evaluate($this->under, $this->user);

    $own = $this->db->connection()->table('anomaly_alerts')->where('transaction_id', $this->under)->first();
    $planted = $this->db->connection()->table('anomaly_alerts')->where('id', $folded)->first();
    $charge = $this->db->connection()->table('transactions')->where('id', $this->under)->first();

    expect($own)->not->toBeNull('the charge was left without an alert because its number was taken')
        ->and((int) $own->id)->not->toBe($folded)
        ->and((int) $own->latest_amount_minor)->toBe((int) $charge->settled_amount_minor)
        ->and($planted)->not->toBeNull('the planted alert was overwritten')
        ->and((int) $planted->transaction_id)->toBe($peerCharge)
        ->and((int) $planted->latest_amount_minor)->toBe(-2050);
});

it('never gives two charges one alert number, however many it opens', function (): void {
    $this->app->make(AnomalyEvaluator::class)->evaluate($this->under, $this->user);

    $ids = $this->db->connection()->table('anomaly_alerts')
        ->where('user_id', $this->user->id)
        ->pluck('id')
        ->all();

    expect($ids)->toHaveCount(1)
        ->and((int) $ids[0])->toBeGreaterThan(0)
        ->and((int) $ids[0])->toBeLessThanOrEqual(PHP_INT_MAX);
});

it('drops an alert stating an amount the charge it names does not have, and keeps a true one', function (): void {
    $this->app->make(AnomalyEvaluator::class)->evaluate($this->under, $this->user);

    $peerCharge = peerNumberOtherChargeOf($this->db, $this->user, $this->under);
    peerNumberPlantAlert($this->db, $this->user, 4242424242424242, $peerCharge, -2050);

    $before = $this->db->connection()->table('anomaly_alerts')->where('user_id', $this->user->id)->count();

    peerNumberRepairMigration()->up();

    $after = $this->db->connection()->table('anomaly_alerts')->where('user_id', $this->user->id)->get();

    expect($before)->toBe(2)
        ->and($after)->toHaveCount(1)
        ->and((int) $after[0]->transaction_id)->toBe($this->under);

    // Re-runnable: a second pass finds nothing left to judge.
    peerNumberRepairMigration()->up();

    expect($this->db->connection()->table('anomaly_alerts')->where('user_id', $this->user->id)->count())->toBe(1);
});
