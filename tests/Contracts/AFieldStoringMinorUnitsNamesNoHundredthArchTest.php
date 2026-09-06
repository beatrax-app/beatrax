<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;

// users.anomaly_min_amount_minor and users.recurring_income_min_amount_minor
// both hold a count of minor units in the reader's own base currency, and
// base_currency is validated against the currencies table — which seeds JPY.
// Copy naming the stored figure "cents" is then false against its own worked
// example: on a yen base, 1000 does not mean ¥10.00, it means ¥1,000.

// The hundredth-unit stem in every script this app ships in. A field whose
// value is a count of minor units cannot name one of these, because the scale
// it would be claiming is the currency's to decide and not the field's.
const MINOR_UNIT_HUNDREDTH_STEMS = '/c[eéê]n[tț]|sent|λεπτ|цент/iu';

/**
 * The copy that names the scale of a stored minor-unit figure: the two
 * settings fields bound to those columns, and the onboarding suffix on the
 * starting-balance input, which is the same claim about the same kind of value.
 *
 * @return array<string, list<string>> lang file, relative to the repo root, => dotted key paths
 */
function minorUnitScaleCopy(string $locale): array
{
    return [
        "Modules/Anomaly/Resources/lang/{$locale}/settings.php" => ['min_amount_label', 'min_amount_help'],
        "Modules/Core/Resources/lang/{$locale}/settings.php" => ['recurring.income_label', 'recurring.income_help'],
        "Modules/Onboarding/Resources/lang/{$locale}/starting_balance.php" => ['minor_units'],
    ];
}

// The stem list is the whole of the verdict, so it is driven over the words it
// has to catch and the ones it must not: a stored figure is described in minor
// units, and a locale naming the unit that way is the fix, not the defect.
it('reads a hundredth in every script it ships, and leaves the minor unit alone', function (): void {
    $hundredths = ['cents', 'centen', 'céntimos', 'centesimi', 'centavos', 'senten', 'λεπτά', 'центи'];
    $units = ['minor units', 'minorenheden', 'unidades menores', 'μικρές μονάδες', 'дрібні одиниці'];

    $missed = array_values(array_filter(
        $hundredths,
        static fn (string $word): bool => preg_match(MINOR_UNIT_HUNDREDTH_STEMS, $word) !== 1,
    ));
    $wrongly = array_values(array_filter(
        $units,
        static fn (string $word): bool => preg_match(MINOR_UNIT_HUNDREDTH_STEMS, $word) === 1,
    ));

    expect($missed)->toBe([], 'These name a hundredth of a unit and the stem list no longer reads them: '.implode(', ', $missed));
    expect($wrongly)->toBe([], 'These name the minor unit, which is the fix rather than the defect, and were flagged: '.implode(', ', $wrongly));
});

it('names no hundredth of a unit in copy describing a stored minor-unit figure', function (): void {
    $claims = [];

    foreach (Locale::cases() as $case) {
        foreach (minorUnitScaleCopy($case->value) as $file => $paths) {
            $lines = require base_path($file);
            expect($lines)->toBeArray($file.' no longer returns a lang array, so every key below reads as empty.');

            foreach ($paths as $path) {
                $line = (string) data_get($lines, $path);
                expect($line)->not->toBe('', $file.' ['.$path.'] is gone, so this rule is reading nothing where it stands.');

                if (preg_match(MINOR_UNIT_HUNDREDTH_STEMS, $line) === 1) {
                    $claims[] = $file." [{$path}]: ".$line;
                }
            }
        }
    }

    sort($claims);

    expect($claims)->toBe([], implode("\n", [
        'These lines name a hundredth of a currency unit for a field that stores minor units:',
        ...$claims,
        '',
        'The column holds minor units of whatever the reader banks in, and JPY has',
        'no minor unit at all — so the copy says one thing while the worked example',
        'beside it, formatted through Money, says another. Name the unit the way',
        'onboarding::starting_balance.minor_units already does in this locale.',
    ]));
});

// The example is only worth printing if it tracks the constant the field
// actually defaults to. Every locale hard-coded 200000 into the recurring line
// while the Blade was passing :minor beside it, so the copy could not follow a
// change to the constant and nothing would have said so.
it('spends the default the Blade passes rather than a numeral of its own', function (): void {
    $sentinel = '4242';

    $stale = [];
    foreach (Locale::cases() as $case) {
        $lines = [
            'anomaly::settings.min_amount_help' => trans(
                'anomaly::settings.min_amount_help',
                ['symbol' => '¤', 'minor' => $sentinel, 'example' => 'EXAMPLE'],
                $case->value,
            ),
            'core::settings.recurring.income_help' => trans(
                'core::settings.recurring.income_help',
                ['minor' => $sentinel, 'example' => 'EXAMPLE'],
                $case->value,
            ),
        ];

        foreach ($lines as $key => $line) {
            if (! is_string($line) || ! str_contains($line, $sentinel)) {
                $stale[] = $case->value.' ['.$key.']: the default never reached the reader — '.(is_string($line) ? $line : $key);
            }
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n", [
        'These lines print a figure the caller did not give them:',
        ...$stale,
        '',
        'The Blade interpolates the constant the field defaults to. A locale that',
        'writes the number out instead keeps rendering the old default forever.',
    ]));
});
