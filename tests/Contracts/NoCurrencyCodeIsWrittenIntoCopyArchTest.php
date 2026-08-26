<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// A currency code is data, not copy. "Settled EUR" over a column that prints
// each row's own currency, and "Amount (EUR)" over a PDF that prints the
// account's, were true only for the ledger they were written against — and
// BaseCurrencyIsTheOnlyEuroArchTest skips lang/ on the grounds that
// translations are data, so nothing was watching them.
/**
 * @return list<string>
 */
function copyCurrencyCodeFiles(): array
{
    $files = glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [];
    sort($files);

    return array_values($files);
}

/**
 * @param  array<array-key, mixed>  $strings
 * @return array<string, string>
 */
function copyCurrencyCodeFlatten(array $strings, string $prefix = ''): array
{
    $flat = [];
    foreach ($strings as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += copyCurrencyCodeFlatten($value, $path);
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

it('never writes a currency code into a translated string', function (): void {
    $codes = array_map(static fn (Currency $case): string => $case->value, Currency::cases());

    $offenders = [];
    foreach (copyCurrencyCodeFiles() as $path) {
        /** @var array<array-key, mixed> $strings */
        $strings = require $path;

        foreach (copyCurrencyCodeFlatten($strings) as $key => $value) {
            foreach ($codes as $code) {
                if (preg_match('/\b'.$code.'\b/', $value) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' ['.$key.'] '.$value;
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These strings name a currency the figure beside them is not guaranteed to be in:',
        ...$offenders,
        '',
        'Name the currency with a placeholder the caller fills from the data, or',
        'leave it out — the amount already carries its own symbol.',
    ]));
});

// A symbol is the same claim as a code, made shorter: "Amount (€)" over a field
// typed in the account's own denomination, and "dips below €0" over a calendar
// drawn in the reader's. Both were true only of the ledger they were written
// against.
it('never writes a currency symbol into a translated string', function (): void {
    $symbols = array_values(Money::SYMBOLS);

    $offenders = [];
    foreach (copyCurrencyCodeFiles() as $path) {
        /** @var array<array-key, mixed> $strings */
        $strings = require $path;

        foreach (copyCurrencyCodeFlatten($strings) as $key => $value) {
            foreach ($symbols as $symbol) {
                if (str_contains($value, $symbol)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' ['.$key.'] '.$value;
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These strings carry a currency symbol the figure beside them may not be in:',
        ...$offenders,
        '',
        'Interpolate the amount, or the symbol, from the currency the caller holds.',
    ]));
});
