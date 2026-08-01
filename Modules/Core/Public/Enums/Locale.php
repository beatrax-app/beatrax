<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The single source of truth for which languages the UI ships. Every seam
// that has to enumerate locales — the settings switcher's allow-list, the
// Accept-Language negotiation, each module's loadTranslationsFrom fallback —
// derives from these cases rather than repeating a bare 'en'/'nl' literal.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
enum Locale: string
{
    case En = 'en';

    case Nl = 'nl';

    // The fallback locale. A missing translation key and an unrecognised
    // Accept-Language both resolve here, matching config/app.php's
    // fallback_locale so the two never disagree.
    public const string DEFAULT = self::En->value;

    // The endonym shown in the switcher — each language named in itself, so
    // a Dutch-only reader still recognises their own option.
    public function label(): string
    {
        return match ($this) {
            self::En => 'English',
            self::Nl => 'Nederlands',
        };
    }

    // The language codes, DEFAULT first, so Symfony's getPreferredLanguage()
    // falls back to English rather than to whichever case is declared first.
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [self::DEFAULT];
        foreach (self::cases() as $case) {
            if ($case->value !== self::DEFAULT) {
                $codes[] = $case->value;
            }
        }

        return $codes;
    }

    public static function isSupported(string $code): bool
    {
        return self::tryFrom($code) instanceof self;
    }
}
