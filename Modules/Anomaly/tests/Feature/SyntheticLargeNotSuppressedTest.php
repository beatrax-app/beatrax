<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

/*
 * CR-02: the synthetic `large` reason that first-time injects (large vs
 * OVERALL spend) must NOT be muted by a per-merchant `large` suppression
 * band (which describes "this merchant's recurring large charge"). A band
 * created from one merchant cannot silently strip the large-amount signal
 * from a brand-new merchant's first charge.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('does not let a per-merchant large band mute a first-time merchant synthetic large (CR-02)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();

    // first-time-large: a €185.00 charge to a never-before-seen merchant,
    // carrying BOTH `large` (synthetic, overall-spend) and `first_time`.
    $fixture = AnomalyCorpusSeeder::load('first-time-large');

    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    // Plant a band-only `large` suppression rule (NULL counterparty — the
    // name-fallback shape created when an unresolved merchant's large alert
    // was dismissed). Per the evaluator's matching, a NULL-counterparty rule
    // wildcard-matches any same-band/currency/direction charge, so WITHOUT
    // the CR-02 provenance guard this band would mute the new merchant's
    // synthetic large. Its band brackets the €185.00 charge.
    $this->db->connection()->table('anomaly_suppression_rules')->insert([
        'user_id' => $user->id,
        'counterparty_id' => null,
        'detector' => 'large',
        'direction' => 'expense',
        'amount_band_low_minor' => (int) round(1.15 * -18500),
        'amount_band_high_minor' => (int) round(0.85 * -18500),
        'currency' => 'EUR',
        'source_anomaly_alert_id' => null,
        'created_at' => '2026-06-13 00:00:00', 'updated_at' => '2026-06-13 00:00:00',
    ]);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->first();

    // The alert fires with BOTH reasons intact — the synthetic large is not
    // stripped, so the large+first_time coupling (D-09) holds.
    expect($alert)->not->toBeNull()
        ->and($alert->reasons)->toContain('large')
        ->and($alert->reasons)->toContain('first_time');
});

it('still mutes a genuine merchant-baseline large via the merchant band (CR-02 leaves real bands working)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();

    // large-above: 5 stable €9.99 Spotify baselines + one €23.49 outlier
    // that trips the merchant-baseline large path (latest_amount_minor is
    // populated -> provenance is merchant-baseline).
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    // Plant a per-merchant `large` band keyed on the SAME (Spotify) merchant
    // bracketing the €23.49 charge.
    $spotifyId = (int) $this->db->connection()->table('transactions')->where('id', $txnId)->value('counterparty_id');
    $this->db->connection()->table('anomaly_suppression_rules')->insert([
        'user_id' => $user->id, 'counterparty_id' => $spotifyId, 'detector' => 'large', 'direction' => 'expense',
        'amount_band_low_minor' => (int) round(1.15 * -2349), 'amount_band_high_minor' => (int) round(0.85 * -2349),
        'currency' => 'EUR', 'source_anomaly_alert_id' => null,
        'created_at' => '2026-06-13 00:00:00', 'updated_at' => '2026-06-13 00:00:00',
    ]);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    // A merchant-baseline large IS eligible for its own band -> suppressed.
    expect(AnomalyAlert::query()->where('transaction_id', $txnId)->exists())->toBeFalse();
});
