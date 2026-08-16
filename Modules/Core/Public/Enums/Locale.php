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
    // Declared in endonym order — Latin script A-Z, then Greek, then
    // Cyrillic — because both switchers list cases() verbatim and a reader
    // scanning a long select for their own language needs somewhere
    // predictable to look. codes() re-sorts for negotiation.
    case Cs = 'cs';

    case Da = 'da';

    case De = 'de';

    case Et = 'et';

    case En = 'en';

    case Es = 'es';

    case Fr = 'fr';

    case Hr = 'hr';

    case It = 'it';

    case Lv = 'lv';

    case Lt = 'lt';

    case Hu = 'hu';

    case Nl = 'nl';

    case Nb = 'nb';

    case Pl = 'pl';

    case Pt = 'pt';

    case Ro = 'ro';

    case Sk = 'sk';

    case Sl = 'sl';

    // Serbian ships in Latin script: it renders without a Cyrillic font
    // fallback on every desktop and mobile target, and it is what Serbian
    // banking software overwhelmingly uses.
    case Sr = 'sr';

    case Fi = 'fi';

    case Sv = 'sv';

    case Tr = 'tr';

    case El = 'el';

    case Bg = 'bg';

    case Uk = 'uk';

    // The fallback locale. A missing translation key and an unrecognised
    // Accept-Language both resolve here, matching config/app.php's
    // fallback_locale so the two never disagree.
    public const string DEFAULT = self::En->value;

    // The endonym shown in the switcher — each language named in itself, so
    // a Dutch-only reader still recognises their own option.
    public function label(): string
    {
        return match ($this) {
            self::Cs => 'Čeština',
            self::Da => 'Dansk',
            self::De => 'Deutsch',
            self::Et => 'Eesti',
            self::En => 'English',
            self::Es => 'Español',
            self::Fr => 'Français',
            self::Hr => 'Hrvatski',
            self::It => 'Italiano',
            self::Lv => 'Latviešu',
            self::Lt => 'Lietuvių',
            self::Hu => 'Magyar',
            self::Nl => 'Nederlands',
            self::Nb => 'Norsk bokmål',
            self::Pl => 'Polski',
            self::Pt => 'Português',
            self::Ro => 'Română',
            self::Sk => 'Slovenčina',
            self::Sl => 'Slovenščina',
            self::Sr => 'Srpski',
            self::Fi => 'Suomi',
            self::Sv => 'Svenska',
            self::Tr => 'Türkçe',
            self::El => 'Ελληνικά',
            self::Bg => 'Български',
            self::Uk => 'Українська',
        };
    }

    // A flag is a country and a locale is a language, so this is a
    // convenience, not a truth: English is 🇬🇧 and Portuguese 🇵🇹 because
    // those are the variants translated here. The endonym stays the label.
    public function flag(): string
    {
        return match ($this) {
            self::Cs => '🇨🇿',
            self::Da => '🇩🇰',
            self::De => '🇩🇪',
            self::Et => '🇪🇪',
            self::En => '🇬🇧',
            self::Es => '🇪🇸',
            self::Fr => '🇫🇷',
            self::Hr => '🇭🇷',
            self::It => '🇮🇹',
            self::Lv => '🇱🇻',
            self::Lt => '🇱🇹',
            self::Hu => '🇭🇺',
            self::Nl => '🇳🇱',
            self::Nb => '🇳🇴',
            self::Pl => '🇵🇱',
            self::Pt => '🇵🇹',
            self::Ro => '🇷🇴',
            self::Sk => '🇸🇰',
            self::Sl => '🇸🇮',
            self::Sr => '🇷🇸',
            self::Fi => '🇫🇮',
            self::Sv => '🇸🇪',
            self::Tr => '🇹🇷',
            self::El => '🇬🇷',
            self::Bg => '🇧🇬',
            self::Uk => '🇺🇦',
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
