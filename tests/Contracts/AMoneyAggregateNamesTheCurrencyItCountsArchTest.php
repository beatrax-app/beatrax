<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
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

// The three shapes a sum says which currency it counts in — a where, a GROUP BY,
// or a select that buckets by the column — all spell that column as a quoted
// literal. Reading the bare word anywhere in the enclosing function also
// excused a `$currency` parameter that never reached the query; measured over
// the tree, all thirty-seven sites the loose read excused name the column in
// quotes, so nothing legitimate is lost by asking for the quotes.
const MONEY_AGGREGATE_NAMES_A_CURRENCY_COLUMN = '/[\'"][^\'"]*currenc[^\'"]*[\'"]/i';

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
        $sites = array_merge($sites, moneyAggregateSitesIn(
            str_replace(base_path().'/', '', $path),
            BackendSourceFiles::codeTokens($path),
        ));
    }

    return $sites;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<array{key: string, path: string, line: int, body: string}>
 */
function moneyAggregateSitesIn(string $relative, array $tokens): array
{
    $sites = [];
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
    expect(count($sites))->toBeGreaterThan(30, 'Read '.count($sites).' money sums, too few for an empty offender list to mean anything.');

    $offenders = [];
    $pinned = [];

    foreach ($sites as $site) {
        if (preg_match(MONEY_AGGREGATE_NAMES_A_CURRENCY_COLUMN, $site['body']) === 1) {
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

    expect(array_keys($pinned))->toBe(
        array_keys(MONEY_AGGREGATE_PINS),
        'A pinned aggregate is no longer reached by the rule it was written for, so the entry excuses nothing and goes.',
    );
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

        $counted += PatternScan::count('/array_sum\s*\(/', $source);

        if (preg_match('/array_sum\s*\(\s*\$[A-Za-z_>\-\[\]\'\w]*currenc\w*/i', $source) !== 1) {
            continue;
        }

        if (array_key_exists($relative, CROSS_CURRENCY_SUM_PINS)) {
            $pinned[$relative] = true;

            continue;
        }

        $offenders[] = $relative;
    }

    expect($counted)->toBeGreaterThan(10, 'Read '.$counted.' array_sum() calls, too few for an empty offender list to mean anything.');

    expect($offenders)->toBe([], implode("\n  ", [
        'array_sum() over a map keyed by currency adds euro-cents to yen. Convert',
        'the buckets first — CrossCurrencyTotal::of() does it and names what it',
        'could not price — or keep the answer per currency. Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(
        array_keys(CROSS_CURRENCY_SUM_PINS),
        'The one place that knowingly adds a map keyed by currency no longer does, so the entry excuses nothing and goes.',
    );
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

it('reads both spellings of a money sum, and asks the currency column be named rather than the word', function (): void {
    $source = <<<'PHP'
        <?php
        final class PlantedTotals
        {
            public function unscoped(): int
            {
                return $this->db->table('transactions')->sum('amount_minor');
            }

            public function scoped(string $currency): int
            {
                return (int) $this->db->table('transactions')
                    ->where('settled_currency', $currency)
                    ->selectRaw('SUM(amount_minor) as total')
                    ->value('total');
            }
        }
        PHP;

    $sites = moneyAggregateSitesIn('Planted.php', BackendSourceFiles::tokensOf('Planted.php', $source));

    expect(array_column($sites, 'key'))->toBe(
        ['Planted.php::unscoped', 'Planted.php::scoped'],
        'a builder sum and a SUM written into SQL are the same act, each read against the function a reader would open',
    );

    expect(preg_match(MONEY_AGGREGATE_NAMES_A_CURRENCY_COLUMN, $sites[0]['body']) === 1)
        ->toBeFalse('a sum naming no currency column is the defect this rule exists for');

    expect(preg_match(MONEY_AGGREGATE_NAMES_A_CURRENCY_COLUMN, $sites[1]['body']) === 1)
        ->toBeTrue('a where on the currency column is one of the three ways a sum says what it counted');

    expect(preg_match(MONEY_AGGREGATE_NAMES_A_CURRENCY_COLUMN, 'function f(string $currency): int { return $q->sum($amountMinor); }') === 1)
        ->toBeFalse('a parameter named currency that never reaches the query says nothing about what was summed');
});
