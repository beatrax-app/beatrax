<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * @link ../../.docs/conventions/00-index.md
 */

// English calls it a counterparty, and Dutch answered with two words: the rule
// form said "Tegenpartij" on the condition and "Winkelier" on the action beside
// it. Winkelier is this locale's word for a MERCHANT, one KIND of counterparty
// — the demo ledger pays a tax office and an employer, and neither keeps a shop.
//
// Parity cannot see it: every key was present and every value non-empty.

/** @return list<string> every locale shipped for counterparty triage */
function counterpartyWordLocales(): array
{
    $locales = [];
    foreach (glob(base_path('Modules/Counterparties/Resources/lang/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $locales[] = basename($dir);
    }
    sort($locales);

    return $locales;
}

/** @return array<string, string> key => value, for one lang file, or [] when it is absent */
function counterpartyWordStrings(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    /** @var array<string, mixed> $loaded */
    $loaded = require $path;

    return array_filter(Arr::dot($loaded), is_string(...));
}

/** @return array<string, array<string, string>> "Module/file.php" => key => English value naming a counterparty */
function counterpartyWordEnglishKeys(): array
{
    $targets = [];
    foreach (glob(base_path('Modules/*/Resources/lang/en/*.php')) ?: [] as $path) {
        $module = basename(dirname($path, 4));
        foreach (counterpartyWordStrings($path) as $key => $value) {
            if (preg_match('/\bcounterpart(y|ies)\b/i', $value) === 1) {
                $targets[$module.'/'.basename($path)][$key] = $value;
            }
        }
    }
    ksort($targets);

    return $targets;
}

/** @return array<string, string> locale => its own lowercased word for a merchant */
function counterpartyWordMerchantTerms(): array
{
    $terms = [];
    foreach (counterpartyWordLocales() as $locale) {
        $merchant = counterpartyWordStrings(
            base_path("Modules/Counterparties/Resources/lang/{$locale}/triage.php")
        )['type_merchant'] ?? null;

        if (is_string($merchant) && $merchant !== '') {
            $terms[$locale] = mb_strtolower($merchant);
        }
    }

    return $terms;
}

it('never answers an English counterparty with the locale word for a merchant', function (): void {
    $targets = counterpartyWordEnglishKeys();
    $merchants = counterpartyWordMerchantTerms();

    // A walk that found nothing would pass while proving nothing.
    expect(count($targets))->toBeGreaterThan(5)
        ->and(count($merchants))->toBeGreaterThan(20);

    $offenders = [];

    foreach ($targets as $file => $keys) {
        [$module, $basename] = explode('/', $file, 2);

        foreach ($merchants as $locale => $merchant) {
            if ($locale === 'en') {
                continue;
            }

            $translated = counterpartyWordStrings(
                base_path("Modules/{$module}/Resources/lang/{$locale}/{$basename}")
            );

            foreach (array_keys($keys) as $key) {
                $value = $translated[$key] ?? null;

                if (is_string($value) && str_contains(mb_strtolower($value), $merchant)) {
                    $offenders[] = "{$locale} {$file} {$key} = \"{$value}\"";
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A merchant is one kind of counterparty, so its word cannot stand for all of them:',
        ...$offenders,
    ]));
});

it('still lets a locale say merchant on the strings whose English says merchant', function (): void {
    // The half of this sweep that must NOT be converted. Each English value
    // below really does say merchant, so the Dutch word for one is the right
    // answer and a later pass must not "fix" it into a counterparty.
    $keptAsMerchant = [
        'Modules/Receipts/Resources/lang/%s/messages.php' => ['conflict.field.counterparty_name', 'winkelier'],
        'Modules/Categorization/Resources/lang/%s/rules.php' => ['footer_note', 'winkelier'],
        'Modules/Categorization/Resources/lang/%s/detail.php' => ['auto_categorized', 'winkelier'],
    ];

    foreach ($keptAsMerchant as $template => [$key, $dutchMerchant]) {
        $english = counterpartyWordStrings(base_path(sprintf($template, 'en')))[$key] ?? null;
        $dutch = counterpartyWordStrings(base_path(sprintf($template, 'nl')))[$key] ?? null;

        expect($english)->toBeString(sprintf($template, 'en').' must still carry '.$key)
            ->and(mb_strtolower((string) $english))->toContain('merchant')
            ->and(mb_strtolower((string) $dutch))->toContain($dutchMerchant);
    }
});

it('gives one counterparty word per locale across the screens that sit together', function (): void {
    $labels = [
        'rule_form.php' => ['field_counterparty', 'action_counterparty'],
        'triage.php' => ['col_counterparty'],
        'rules.php' => ['chip_counterparty'],
    ];

    $offenders = [];

    foreach (counterpartyWordLocales() as $locale) {
        $found = [];

        foreach ($labels as $file => $keys) {
            $strings = counterpartyWordStrings(
                base_path("Modules/Categorization/Resources/lang/{$locale}/{$file}")
            );

            foreach ($keys as $key) {
                if (isset($strings[$key])) {
                    // "Tegenpartij: :path" has to compare as its leading term.
                    $found[$file.'.'.$key] = mb_strtolower(trim(explode(':', $strings[$key], 2)[0]));
                }
            }
        }

        if (count($found) > 1 && count(array_unique($found)) > 1) {
            $offenders[] = $locale.' — '.json_encode($found, JSON_UNESCAPED_UNICODE);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'One English word, more than one in this locale, on controls that sit beside each other:',
        ...$offenders,
    ]));
});
