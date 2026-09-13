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

    // The space CLDR puts between a figure and what follows it — a group mark,
    // a currency symbol, a percent sign, a shortened thousand. It is no-break
    // in every one of them so the two halves cannot land on separate lines.
    private const string NBSP = "\u{00A0}";

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
            self::Lv, self::Nb, self::Pl, self::Sk, self::Sv, self::Uk => self::NBSP,
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
        return $this === self::En || $this === self::Tr ? '' : self::NBSP;
    }

    // Dutch is the only shipped locale whose negative pattern keeps the symbol
    // in front of the sign (€ -1.234,50); everywhere else the sign leads.
    public function signPrecedesSymbol(): bool
    {
        return $this !== self::Nl;
    }

    // What each locale shortens a thousand to, transcribed from CLDR's short
    // compact patterns for the same reason the marks above are: on device ICU
    // can only answer for English. A literal "k" is English's own abbreviation,
    // and English does not even use that one — it writes "K".

    // German answers null: CLDR gives it no short form below a million, so the
    // figure is written out, which is what a German reader is meant to see.
    public function compactThousands(): ?string
    {
        return match ($this) {
            self::Cs, self::Hr, self::Sk, self::Sl => self::NBSP.'tis.',
            self::Da => self::NBSP.'t',
            self::De => null,
            self::Et => self::NBSP.'tuh',
            self::En, self::It, self::Nl => 'K',
            self::Es, self::Pt => self::NBSP.'mil',
            self::Fr => self::NBSP.'k',
            self::Lv, self::Lt => self::NBSP."t\u{016B}kst.",
            self::Hu => self::NBSP.'E',
            self::Nb => 'k',
            self::Pl => self::NBSP.'tys.',
            self::Ro => self::NBSP.'K',
            self::Sr => self::NBSP."\u{0445}\u{0438}\u{0459}.",
            self::Fi => self::NBSP.'t.',
            self::Sv => self::NBSP.'tn',
            self::Tr => self::NBSP.'B',
            self::El => self::NBSP."\u{03C7}\u{03B9}\u{03BB}.",
            self::Bg => self::NBSP."\u{0445}\u{0438}\u{043B}.",
            self::Uk => self::NBSP."\u{0442}\u{0438}\u{0441}.",
        };
    }

    // Every shipped locale shortens a million, German included, so this one
    // never answers null where compactThousands() does.
    public function compactMillions(): string
    {
        return match ($this) {
            self::Cs, self::Hr, self::Ro, self::Sk => self::NBSP.'mil.',
            self::Da, self::Sl => self::NBSP.'mio.',
            self::De => self::NBSP.'Mio.',
            self::Et, self::Pl => self::NBSP.'mln',
            self::En => 'M',
            self::Es, self::Fr, self::Hu => self::NBSP.'M',
            self::It => self::NBSP.'Mln',
            self::Lv, self::Fi => self::NBSP.'milj.',
            self::Lt, self::Nl => self::NBSP.'mln.',
            self::Nb => self::NBSP.'mill.',
            self::Pt => self::NBSP.'mi',
            self::Sr => self::NBSP."\u{043C}\u{0438}\u{043B}.",
            self::Sv => self::NBSP.'mn',
            self::Tr => self::NBSP.'Mn',
            self::El => self::NBSP."\u{03B5}\u{03BA}.",
            self::Bg => self::NBSP."\u{043C}\u{043B}\u{043D}.",
            self::Uk => self::NBSP."\u{043C}\u{043B}\u{043D}",
        };
    }

    // Where the percent sign sits relative to the digits. Turkish is the only
    // shipped locale that writes it in front (%42), transcribed from each
    // locale's ICU percent pattern for the same reason the marks above are: on
    // device ICU can only answer for English.
    public function percentSignBeforeDigits(): bool
    {
        return $this === self::Tr;
    }

    // Thirteen locales keep a no-break space between the figure and the sign
    // (42 %); the rest close it up (42%). The space is the one CLDR names, and
    // it is no-break on purpose: a plain one lets the sign wrap to the next
    // line on its own.
    public function percentGap(): string
    {
        return match ($this) {
            self::Cs, self::Da, self::De, self::Es, self::Fi, self::Fr, self::Hr,
            self::Lt, self::Nb, self::Ro, self::Sk, self::Sl, self::Sv => self::NBSP,
            self::Bg, self::El, self::En, self::Et, self::Hu, self::It, self::Lv,
            self::Nl, self::Pl, self::Pt, self::Sr, self::Tr, self::Uk => '',
        };
    }

    // Seven locales write U+2212 MINUS SIGN where the rest write the ASCII
    // hyphen-minus, transcribed from ICU for the same reason the marks above
    // are: on device ICU can only answer for English, and a phone spelling a
    // negative differently from the desktop beside it is the defect.
    public function minusSign(): string
    {
        return match ($this) {
            self::Et, self::Fi, self::Hr, self::Lt,
            self::Nb, self::Sl, self::Sv => "\u{2212}",
            self::Bg, self::Cs, self::Da, self::De, self::El, self::En,
            self::Es, self::Fr, self::Hu, self::It, self::Lv, self::Nl,
            self::Pl, self::Pt, self::Ro, self::Sk, self::Sr, self::Tr,
            self::Uk => '-',
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

    // A platform spells its language as a BCP-47 tag — "nl-NL", "pt-BR",
    // "en" — and the registry is keyed by the primary subtag alone. Region is
    // dropped rather than matched: a Dutch reader in Belgium reads the same
    // Dutch, and no shipped locale differs by region.
    public static function fromTag(string $tag): ?string
    {
        $primary = mb_strtolower(explode('-', str_replace('_', '-', trim($tag)))[0]);

        return self::isSupported($primary) ? $primary : null;
    }
}
