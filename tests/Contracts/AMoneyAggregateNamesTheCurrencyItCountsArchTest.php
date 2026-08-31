<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\MoneySourceShape;

// Minor units of two currencies do not add: 2 700 euro-cents and 2 700 000 yen
// are the same integer and different money. A sum that names no currency prints
// one line's figure under another line's sign — the pots header read "allocated
// ¥270.000" for pots holding EUR 2.700,00, and the reader's funding ceiling
// became ¥15.000 where the euro line had EUR 105.714 left.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam

// A sum passes by naming a currency column somewhere in the function that
// builds it — as a predicate, as a GROUP BY, or in the select that buckets by
// it. Each entry below is a sum that names none, and why it still answers for
// exactly one currency. `proves` re-checks the reason against the code.
const MONEY_AGGREGATE_PINS = [
    'Modules/Ledger/Internal/Services/AsnCsvRowSummary.php::for' => [
        'reason' => 'SUM(id) over row ids, which is a checksum and not money at all',
        'proves' => '/SUM\(id\)/',
    ],
    'Modules/Pots/Internal/Services/PotRowLoader.php::balanceForPot' => [
        'reason' => 'one pot, and pots.currency is frozen at creation, so the movements it sums are all in that one currency',
        'proves' => "/where\('pot_id', \\\$potId\)/",
    ],
    'Modules/Pots/Public/Services/PotBalanceQuery.php::netMovementForPotSince' => [
        'reason' => 'one pot, for the same reason',
        'proves' => "/where\('pot_id', \\\$potId\)/",
    ],
    'Modules/Reports/Internal/Aggregation/ReportMetric.php::sumExpr' => [
        'reason' => 'a SQL fragment with no query of its own; CurrencyModeApplier re-runs the dimension query one settled_currency at a time and every caller takes the fragment from there',
        'proves' => '/@return literal-string/',
    ],
];

// Adding a map keyed BY currency is the arithmetic in its purest form, so the
// only entry here is the one place that does it knowingly.
const CROSS_CURRENCY_SUM_PINS = [
    'Modules/Budgets/Public/Services/EnvelopePeriodRekeyer.php' => [
        'reason' => 'a bucket the rate table cannot price whole is left summed rather than losing the part that has no rate, and the row keeps the bucket own currency rather than claiming the base one',
        'proves' => '/\$whole === null \? \$bucket\[\'currency\'\] : \$baseCurrency/',
    ],
];

/**
 * Every SUM in the tree, as "path::function" => the aggregate's source text.
 * A builder `->sum('col')` and a `SUM(...)` written into SQL are the same act.
 *
 * @return list<array{key: string, path: string, line: int, body: string}>
 */
function moneyAggregateSites(): array
{
    $sites = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);
        $functions = MoneySourceShape::functions($tokens);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            $isBuilderSum = $token[0] === T_STRING
                && $token[1] === 'sum'
                && moneyAggregateFollowsAnArrow($tokens, $i);
            $isSqlSum = in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\bSUM\s*\(/i', $token[1]) === 1;

            if (! $isBuilderSum && ! $isSqlSum) {
                continue;
            }

            // The named declaration, not the builder closure the sum sits in:
            // the currency predicate is written a few lines above it, in the
            // method a reader would open.
            $enclosing = MoneySourceShape::enclosing($functions, $i);
            $sites[] = [
                'key' => $relative.'::'.MoneySourceShape::enclosingName($functions, $i),
                'path' => $relative,
                'line' => $token[2],
                'body' => $enclosing['body'] ?? implode('', array_map(
                    static fn (array|string $t): string => is_array($t) ? $t[1] : $t,
                    $tokens,
                )),
            ];
        }
    }

    return $sites;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function moneyAggregateFollowsAnArrow(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if (trim($text) === '') {
            continue;
        }

        return is_array($tokens[$i])
            && in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    return false;
}

it('names the currency every money sum in the tree is counting', function (): void {
    $sites = moneyAggregateSites();

    // A walk that reads nothing reports a clean tree. The ledger alone sums
    // money in well over a dozen places.
    expect(count($sites))->toBeGreaterThan(30);

    $offenders = [];
    $pinned = [];

    foreach ($sites as $site) {
        if (preg_match('/currency/i', $site['body']) === 1) {
            continue;
        }

        if (array_key_exists($site['key'], MONEY_AGGREGATE_PINS)) {
            $pinned[$site['key']] = true;

            continue;
        }

        $offenders[] = $site['path'].':'.$site['line'].' in '.$site['key'];
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A money aggregate answers for exactly one currency, so it says which:',
        'a where on the currency column, a GROUP BY it, or a select that buckets',
        'by it. Without one, the figure is minor units of whatever happened to be',
        'in range, added together and printed under a single sign. Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(array_keys(MONEY_AGGREGATE_PINS));
});

it('adds a map keyed by currency only where that is a decision somebody made', function (): void {
    $offenders = [];
    $pinned = [];
    $counted = 0;

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            BackendSourceFiles::codeTokens($path),
        ));

        $counted += preg_match_all('/array_sum\s*\(/', $source);

        if (preg_match('/array_sum\s*\(\s*\$[A-Za-z_>\-\[\]\'\w]*currenc\w*/i', $source) !== 1) {
            continue;
        }

        if (array_key_exists($relative, CROSS_CURRENCY_SUM_PINS)) {
            $pinned[$relative] = true;

            continue;
        }

        $offenders[] = $relative;
    }

    expect($counted)->toBeGreaterThan(10);

    expect($offenders)->toBe([], implode("\n  ", [
        'array_sum() over a map keyed by currency adds euro-cents to yen. Convert',
        'the buckets first — CrossCurrencyTotal::of() does it and names what it',
        'could not price — or keep the answer per currency. Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(array_keys(CROSS_CURRENCY_SUM_PINS));
});

it('still holds each pinned aggregate to the reason it was granted for', function (): void {
    foreach (MONEY_AGGREGATE_PINS as $key => $pin) {
        [$relative] = explode('::', $key, 2);
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $key.' no longer reads as "'.$pin['reason'].'"');
    }

    foreach (CROSS_CURRENCY_SUM_PINS as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});
