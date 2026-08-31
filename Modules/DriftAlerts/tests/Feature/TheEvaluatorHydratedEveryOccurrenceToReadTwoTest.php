<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Tests\Support\DriftCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function tehoLongHistory(int $months): array
{
    $transactions = [];
    for ($i = 0; $i < $months; $i++) {
        $date = CarbonImmutable::parse('2020-01-15')->addMonthsNoOverflow($i)->toDateString();
        $amount = $i === $months - 1 ? -1149 : -999;
        $transactions[] = [
            'account_id' => null,
            'type' => 'expense',
            'posted_at' => $date,
            'booked_at' => $date,
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'original_amount_minor' => $amount,
            'original_currency' => 'EUR',
            'counterparty_normalized' => 'long-lived-subscription',
            'counterparty_iban' => null,
        ];
    }

    return ['transactions' => $transactions, 'expected' => ['alerts' => []]];
}

function tehoUser(): User
{
    return User::query()->create([
        'username' => 'teho-'.bin2hex(random_bytes(5)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The evaluator reads two occurrences (three since the prior billing interval
// matters), and read the entire history to reach them — a full DTO hydration
// per occurrence on a series that has been running for years.
it('bounds the occurrence read instead of hydrating the whole history', function (): void {
    $user = tehoUser();
    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);

    $seriesId = DriftCorpusSeeder::replay($this->db, $user, tehoLongHistory(60), $evaluator);

    $occurrenceReads = [];
    $this->db->connection()->listen(function (object $query) use (&$occurrenceReads): void {
        $sql = (string) $query->sql;
        if (str_contains($sql, 'recurring_series_occurrences') && str_starts_with($sql, 'select')) {
            $occurrenceReads[] = $sql;
        }
    });

    $evaluator->evaluateForSeries($seriesId, $user);

    expect($occurrenceReads)->not->toBeEmpty();
    foreach ($occurrenceReads as $sql) {
        expect($sql)->toContain('limit');
    }
});

it('still opens the one correct alert on a series with sixty occurrences', function (): void {
    $user = tehoUser();
    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);

    $seriesId = DriftCorpusSeeder::replay($this->db, $user, tehoLongHistory(60), $evaluator);

    /** @var DriftAlert $alert */
    $alert = DriftAlert::query()->where('recurring_series_id', $seriesId)->sole();
    expect($alert->baseline_amount_minor)->toBe(-999);
    expect($alert->latest_amount_minor)->toBe(-1149);
    expect($alert->annualized_impact_minor)->toBe(-1800);
});
