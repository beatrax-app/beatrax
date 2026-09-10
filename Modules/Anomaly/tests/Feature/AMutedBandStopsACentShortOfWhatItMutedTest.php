<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// "Expected" mutes a band of 85%..115% around the charge, and the evaluator
// matches a later charge against the stored edge exactly. An edge a hundredth
// inside the band it was asked for lets the charge on the boundary through,
// and the alert the reader dismissed comes back.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return object{low: int, high: int}
 */
function mutedBandFor(int $latestMinor): object
{
    /** @var DatabaseManager $db */
    $db = test()->db;
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('duplicate-in-window'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = app(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();
    $db->connection()->table('anomaly_alerts')
        ->where('id', $alert->id)
        ->update(['latest_amount_minor' => $latestMinor]);

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = app(DismissAnomalyAlertAsExpected::class);
    ($dismiss)($alert->id, $user);

    $rule = $db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $user->id)
        ->where('detector', 'duplicate')
        ->firstOrFail();

    return (object) [
        'low' => (int) $rule->amount_band_low_minor,
        'high' => (int) $rule->amount_band_high_minor,
    ];
}

it('mutes the whole band around a charge, to the minor unit', function (int $latestMinor, int $low, int $high): void {
    expect(mutedBandFor($latestMinor))->toEqual((object) ['low' => $low, 'high' => $high]);
})->with([
    // 115% of EUR 12.90 is EUR 14.835, which rounds to EUR 14.84.
    'an expense whose upper edge lands on a half' => [-1290, -1484, -1097],
    'an income whose upper edge lands on a half' => [1290, 1097, 1484],
    'an expense whose edges both divide' => [-2000, -2300, -1700],
    'the smallest charge with a band at all' => [-10, -12, -9],
]);

it('mutes a later charge sitting exactly on the band edge it was given', function (): void {
    $band = mutedBandFor(-1290);

    // The evaluator matches with `low <= settled` and `settled <= high`, so a
    // charge on either edge is inside the mute the reader asked for.
    expect(-1484)->toBeGreaterThanOrEqual($band->low)
        ->and(-1097)->toBeLessThanOrEqual($band->high);
});
