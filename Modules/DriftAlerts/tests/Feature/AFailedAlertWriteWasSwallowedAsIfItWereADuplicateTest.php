<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
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
function afawFixture(): array
{
    $transactions = [];
    foreach ([['2026-03-15', -999], ['2026-04-15', -1149]] as [$date, $amount]) {
        $transactions[] = [
            'account_id' => null,
            'type' => 'expense',
            'posted_at' => $date,
            'booked_at' => $date,
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'original_amount_minor' => $amount,
            'original_currency' => 'EUR',
            'counterparty_normalized' => 'spotify',
            'counterparty_iban' => null,
        ];
    }

    return ['transactions' => $transactions, 'expected' => ['alerts' => []]];
}

function afawUser(): User
{
    return User::query()->create([
        'username' => 'afaw-'.bin2hex(random_bytes(5)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The catch had an empty body and no classification, so a lock timeout or a
// full disk produced no row, no event and no log — indistinguishable from the
// idempotent re-detect it was written for.
it('lets a write failure that is not a duplicate surface instead of suppressing the alert', function (): void {
    $user = afawUser();
    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);

    $seriesId = DriftCorpusSeeder::replay($this->db, $user, afawFixture(), $evaluator);
    $this->db->connection()->statement('DROP TABLE drift_alerts');

    expect(fn () => $evaluator->evaluateForSeries($seriesId, $user))->toThrow(QueryException::class);
});

it('opens exactly one alert and announces it once', function (): void {
    Event::fake([DriftAlertOpened::class]);
    $user = afawUser();
    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);

    $seriesId = DriftCorpusSeeder::replay($this->db, $user, afawFixture(), $evaluator);

    expect(DriftAlert::query()->where('recurring_series_id', $seriesId)->count())->toBe(1);
    Event::assertDispatchedTimes(DriftAlertOpened::class, 1);
});

// The dispatch used to sit inside the insert's try, so re-running had to be
// silent for the right reason: the row is already there.
it('re-running for the same latest occurrence writes nothing and announces nothing', function (): void {
    $user = afawUser();
    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);

    $seriesId = DriftCorpusSeeder::replay($this->db, $user, afawFixture(), $evaluator);

    Event::fake([DriftAlertOpened::class]);
    $evaluator->evaluateForSeries($seriesId, $user);

    expect(DriftAlert::query()->where('recurring_series_id', $seriesId)->count())->toBe(1);
    Event::assertNotDispatched(DriftAlertOpened::class);
});
