<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// The "ignore anything under" setting is typed and shown in the reader's own
// currency, and was then compared against whatever minor units the row settled
// in. A yen has no minor unit at all, so a JPY1,200 charge — under EUR8 —
// carried the integer 1200 straight past a floor meaning EUR10.00.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');

    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Jpy->value,
        'rate_date' => '2026-06-16',
        'rate' => '159.00',
        'source' => 'ecb',
        'created_at' => '2026-06-16 00:00:00',
        'updated_at' => '2026-06-16 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function floorFixtureInYen(int $minor): array
{
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    foreach (['history', 'transaction'] as $section) {
        $rows = $section === 'history' ? $fixture['history'] : [$fixture['transaction']];
        foreach ($rows as $i => $row) {
            $row['amount_minor'] = $minor;
            $row['currency'] = Currency::Jpy->value;
            $row['settled_amount_minor'] = $minor;
            $row['settled_currency'] = Currency::Jpy->value;
            if ($section === 'history') {
                $fixture['history'][$i] = $row;
            } else {
                $fixture['transaction'] = $row;
            }
        }
    }

    return $fixture;
}

it('leaves a yen charge worth less than the floor alone', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    // JPY 1,200 is about EUR 7.55 at 159 to the euro — under the EUR 10.00
    // floor the fixture sets, while its raw integer 1200 is over it.
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, floorFixtureInYen(-1200));

    $this->app->make(AnomalyEvaluator::class)->evaluate($txnId, $user->fresh());

    expect($this->db->connection()->table('anomaly_alerts')->where('transaction_id', $txnId)->count())->toBe(0);
});

it('still raises the yen charge that is worth more than the floor', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    // JPY 4,000 is about EUR 25.16 — over the same floor on either reading, so
    // the guard cannot have simply stopped the detector firing on yen.
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, floorFixtureInYen(-4000));

    $this->app->make(AnomalyEvaluator::class)->evaluate($txnId, $user->fresh());

    expect($this->db->connection()->table('anomaly_alerts')->where('transaction_id', $txnId)->count())->toBe(1);
});
