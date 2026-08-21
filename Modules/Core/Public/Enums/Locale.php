<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// Every seam that enumerates locales — the settings switcher, Accept-Language
// negotiation, each module's loadTranslationsFrom fallback — derives from here.
enum Locale: string
{
    // Declared in endonym order — Latin script A-Z, then Greek, then Cyrillic —
    // because both switchers list cases() verbatim and a reader scanning a long
    // select needs a predictable place to look. codes() re-sorts for negotiation.
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

    // A missing translation key and an unrecognised Accept-Language both land
    // here; it matches config/app.php's fallback_locale so the two never differ.
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

    // Transcribed from ICU data rather than read from ext-intl: the mobile PHP
    // build ships ICU with English-only locale data, so on device the library
    // cannot answer the question at all.
    public function groupMark(): string
    {
        return match ($this) {
            self::En => ',',
            self::Fr => "\u{202F}",
            self::Bg, self::Cs, self::Et, self::Fi, self::Hu, self::Lt,
            self::Lv, self::Nb, self::Pl, self::Sk, self::Sv, self::Uk => "\u{00A0}",
            self::Da, self::De, self::El, self::Es, self::Hr, self::It,
            self::Nl, self::Pt, self::Ro, self::Sl, self::Sr, self::Tr => '.',
        };
    }

    public function decimalMark(): string
    {
        return $this === self::En ? '.' : ',';
    }

    // Where the currency symbol sits relative to the digits, transcribed from
    // each locale's ICU currency pattern for the same reason the marks above
    // are: on device ICU can only answer for English.
    public function symbolBeforeAmount(): bool
    {
        return match ($this) {
            self::En, self::Nl, self::Pt, self::Tr => true,
            self::Bg, self::Cs, self::Da, self::De, self::El, self::Es,
            self::Et, self::Fi, self::Fr, self::Hr, self::Hu, self::It,
            self::Lt, self::Lv, self::Nb, self::Pl, self::Ro, self::Sk,
            self::Sl, self::Sr, self::Sv, self::Uk => false,
        };
    }

    // English and Turkish write the symbol against the digits (€1,234.56);
    // every other locale keeps a non-breaking space between the two.
    public function symbolGap(): string
    {
        return $this === self::En || $this === self::Tr ? '' : "\u{00A0}";
    }

    // Dutch is the only shipped locale whose negative pattern keeps the symbol
    // in front of the sign (€ -1.234,50); everywhere else the sign leads.
    public function signPrecedesSymbol(): bool
    {
        return $this !== self::Nl;
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
