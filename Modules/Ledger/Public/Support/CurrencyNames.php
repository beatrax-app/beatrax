<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use JsonException;
use Modules\Core\Public\Enums\Locale;

// The currencies this install can report in, and what each is called in each
// language it ships. Transcribed from ICU by scripts/generate_currency_names.php
// rather than read from ext-intl at render time: the mobile build bundles ICU
// with English-only data, which answers every locale in English and no error.
/**
 * @link ../../../../.docs/features/ledger/currency-names.md
 */
final class CurrencyNames
{
    private const string TRANSCRIPT = __DIR__.'/../../Resources/currency-names.json';

    /** @var array<string, array<string, string>>|null */
    private static ?array $transcript = null;

    /** @return list<string> */
    public static function codes(): array
    {
        $codes = array_keys(self::forLocale(Locale::DEFAULT));
        sort($codes);

        return $codes;
    }

    /** @return array<string, string> */
    public static function forLocale(string $locale): array
    {
        $transcript = self::$transcript ??= self::read();

        return $transcript[$locale] ?? $transcript[Locale::DEFAULT] ?? [];
    }

    // The transcript is a bundled file that cannot change while a process
    // lives, so only a test has reason to drop the cache.
    public static function forget(): void
    {
        self::$transcript = null;
    }

    /** @return array<string, array<string, string>> */
    private static function read(): array
    {
        $raw = @file_get_contents(self::TRANSCRIPT);

        try {
            /** @var mixed $decoded */
            $decoded = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A picker of bare codes is a poor screen; a picker that throws is
            // no screen at all, and the file ships with the application.
            $decoded = null;
        }

        return is_array($decoded) ? self::names($decoded) : [];
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array<string, array<string, string>>
     */
    private static function names(array $decoded): array
    {
        $names = [];
        foreach ($decoded as $locale => $byCode) {
            if (! is_string($locale) || ! is_array($byCode)) {
                continue;
            }

            foreach ($byCode as $code => $name) {
                if (is_string($code) && is_string($name) && $name !== '') {
                    $names[$locale][$code] = $name;
                }
            }
        }

        return $names;
    }
}
