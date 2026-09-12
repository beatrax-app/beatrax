<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

$rrhSeed = static function (string $date, string $quote, string $rate, string $source): void {
    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => $quote,
        'rate_date' => $date,
        'rate' => $rate,
        'source' => $source,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
};

// The plan of the statement the service actually ran, rather than one rebuilt
// beside it: a rebuilt copy stops describing the read the moment it drifts.
$rrhPlanOf = static function (DatabaseManager $db, callable $run): string {
    $captured = [];
    DB::listen(static function (QueryExecuted $query) use (&$captured): void {
        if (str_contains($query->sql, 'exchange_rates')) {
            $captured[] = [$query->sql, $query->bindings];
        }
    });

    $run();

    expect($captured)->not->toBeEmpty();
    [$sql, $bindings] = $captured[0];

    return implode("\n", array_map(
        static fn (object $row): string => (string) ($row->detail ?? ''),
        $db->connection()->select('EXPLAIN QUERY PLAN '.$sql, $bindings),
    ));
};

// The answer was always one row per pair. The read that produced it was the
// aggregate re-run once per row of the table, so its cost was the rate history
// rather than the currency count — on a phone that history only grows.
it('resolves the newest rate per pair without a subquery per row of the table', function () use ($rrhPlanOf): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $service = new ExchangeRateService($db);

    $plan = $rrhPlanOf($db, static fn () => $service->convertToBase(Money::ofMinor(10000, 'USD'), 'EUR'));

    expect($plan)->not->toContain('CORRELATED SCALAR SUBQUERY')
        ->and($plan)->toContain('SEARCH er USING INDEX exchange_rates_latest_lookup');
});

it('resolves the rate in effect on a date without a subquery per row of the table', function () use ($rrhPlanOf): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $service = new ExchangeRateService($db);

    $plan = $rrhPlanOf($db, static fn () => $service->convertAtDate(Money::ofMinor(10000, 'USD'), 'EUR', '2026-06-07'));

    expect($plan)->not->toContain('CORRELATED SCALAR SUBQUERY')
        ->and($plan)->toContain('SEARCH er USING INDEX exchange_rates_latest_lookup');
});

// Two providers can each hold a row for one pair and one day: the registry
// falls through on failure, and the unique index is keyed by source. Which of
// them supplies the rate, and which one the figure names as its source, was
// decided by a tie neither ORDER BY broke.
it('prices one pair and one day from the later of two providers, not from whichever arrives last', function () use ($rrhSeed): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rrhSeed('2026-07-01', 'USD', '1.1000', 'ecb');
    $rrhSeed('2026-07-01', 'USD', '1.2000', 'frankfurter');

    $result = (new ExchangeRateService($db))->convertToBase(Money::ofMinor(12000, 'USD'), 'EUR');

    expect($result->converted->toMinor())->toBe(10000)
        ->and($result->source)->toBe('frankfurter')
        ->and($result->asOf?->toDateString())->toBe('2026-07-01');
});

it('prices it from the other provider when that one wrote last', function () use ($rrhSeed): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rrhSeed('2026-07-01', 'USD', '1.2000', 'frankfurter');
    $rrhSeed('2026-07-01', 'USD', '1.1000', 'ecb');

    $result = (new ExchangeRateService($db))->convertToBase(Money::ofMinor(11000, 'USD'), 'EUR');

    expect($result->converted->toMinor())->toBe(10000)
        ->and($result->source)->toBe('ecb')
        ->and($result->asOf?->toDateString())->toBe('2026-07-01');
});

// The bundled snapshot stays a floor rather than an answer: it ships with the
// app, so a live feed's row for the same pair and day outranks it however late
// the snapshot was written. The source class decides first; the id only breaks
// what is left.
it('keeps a live provider ahead of the bundled snapshot written after it', function () use ($rrhSeed): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rrhSeed('2026-07-01', 'USD', '1.1000', 'ecb');
    $rrhSeed('2026-07-01', 'USD', '9.9000', 'bundled');

    $result = (new ExchangeRateService($db))->convertToBase(Money::ofMinor(11000, 'USD'), 'EUR');

    expect($result->converted->toMinor())->toBe(10000)
        ->and($result->source)->toBe('ecb');
});
