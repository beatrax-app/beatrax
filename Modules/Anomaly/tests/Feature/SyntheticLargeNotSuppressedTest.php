<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('does not let a per-merchant large band mute a first-time merchant synthetic large (CR-02)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();

    // first-time-large: a €185.00 charge to a never-before-seen merchant.
    $fixture = AnomalyCorpusSeeder::load('first-time-large');

    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    // A NULL-counterparty rule wildcard-matches any same-band/currency/
    // direction charge, so without the provenance guard this band — which
    // brackets €185.00 — would mute a merchant the user has never paid.
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

    expect($alert)->not->toBeNull()
        ->and($alert->reasons)->toContain('large')
        ->and($alert->reasons)->toContain('first_time');
});

it('still mutes a genuine merchant-baseline large via the merchant band (CR-02 leaves real bands working)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();

    // large-above: 5 stable €9.99 Spotify baselines plus one €23.49 outlier,
    // so `large` here comes from the merchant baseline, not the synthetic path.
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

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

    // A merchant-baseline large IS eligible for its own band.
    expect(AnomalyAlert::query()->where('transaction_id', $txnId)->exists())->toBeFalse();
});
