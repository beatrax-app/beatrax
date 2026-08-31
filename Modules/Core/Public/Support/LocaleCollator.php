<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Collator;
use Error;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use IntlException;
use Modules\Core\Public\Enums\Locale;
use Normalizer;

// The ordering seam for any list the reader scans by name. Byte comparison
// files every accented name after Z and knows no alphabet but ASCII's, so a
// Greek, Polish or Dutch reader gets an order that is not their own — and a
// picker with no search box is only as usable as its order.
/**
 * @link ../../../../.docs/features/core/sorting-without-icu.md
 */
final class LocaleCollator
{
    // Kept off the fold's own regex literal so the two spellings of "an
    // accent is not a letter" cannot drift: enclosing marks matter for the
    // Cyrillic and Greek names ICU would otherwise have ordered.
    private const string COMBINING_MARKS = '/[\p{Mn}\p{Me}]+/u';

    // A ligature the reader's own alphabet does not carry collates as the
    // letters it spells; where it IS one of their letters — Danish æ, Finnish
    // ä — the table below lists it and this map is not consulted.
    private const array EXPANSIONS = [
        'æ' => 'ae',
        'œ' => 'oe',
        'ĳ' => 'ij',
        'ß' => 'ss',
        'þ' => 'th',
    ];

    // Which accent a letter carries separates two names no earlier letter
    // has, and ICU ranks the accents in this order in every shipped language:
    // acute, grave, breve, circumflex, caron, ring, diaeresis, double acute,
    // tilde, dot above, cedilla, ogonek, macron, and the three below-marks.
    private const array MARK_ORDER = [
        "\u{0301}", "\u{0300}", "\u{0306}", "\u{0302}", "\u{030C}", "\u{030A}",
        "\u{0308}", "\u{030B}", "\u{0303}", "\u{0307}", "\u{0327}", "\u{0328}",
        "\u{0304}", "\u{0323}", "\u{0326}", "\u{0331}",
    ];

    // Weights on the same scale as MARK_ORDER, which counts by twos to leave
    // room for them: ICU files a stroked or final-form letter between two
    // accents rather than after every one, so ø falls between Ȯ and O-cedilla.
    private const array STROKE_WEIGHT = [
        'ς' => 1,
        'ø' => 21,
        'đ' => 27,
        'ł' => 27,
    ];

    // Punctuation is not ordered by codepoint either: ICU files the hyphen
    // early and the ampersand late, the same way in all twenty-six, so
    // "Jansen-de Vries" and "Jansen & Vries" order alike on both halves.
    private const string PUNCTUATION = " \u{00A0}_-\u{2013}\u{2014},;:!?.'\u{2019}\"()[]{}@*/\\&#%`^+<=>|~$";

    // Above every punctuation mark the table above names, so one it does not
    // still lands among the punctuation rather than among the letters.
    private const int UNLISTED_PUNCTUATION = 1000;

    // An accent MARK_ORDER does not name still ranks as an accent rather than
    // as a letter of its own, and below a ligature spelled out.
    private const int UNLISTED_MARK = 33;

    // The third case a letter can be in, after the small one and the capital,
    // which are '0' and '1'.
    private const string COMPATIBILITY_CASE = '2';

    private const string GREEK = 'α β γ δ ε ζ η θ ι κ λ μ ν ξ ο π ρ σ/ς τ υ φ χ ψ ω';

    private const string CYRILLIC = 'а б в г/ґ д ђ е є ж з и і й ј к л љ м н њ о п р с т ћ у ф х ц ч џ ш щ ъ ы ь э ю я';

    private const string LATIN = 'a b c d/đ/ð e f g h i ı j k l/ł m n o/ø p q r s t u v w x y z þ';

    // Each reader's own alphabet in their own order, transcribed from ICU's
    // collation weights. A slash joins letters it ranks as one letter but
    // still tells apart, an equals two spellings it cannot; a letter absent
    // from the line gives up its accents to the base it decomposes to.
    private const array ORDER = [
        'cs' => 'a b c č d/đ/ð e f g h ch i ı j k l/ł m n o/ø p q r ř s š t u v w x y z ž þ '.self::GREEK.' '.self::CYRILLIC,
        'da' => 'a b c d/đ/ð e f g h i ı j k l/ł m n o p q r s t u v w x y/ü/ű z æ/ä ø/ö/ő å/aa/aå '.self::GREEK.' '.self::CYRILLIC,
        'de' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'et' => 'a b c d/đ/ð e f g h i ı j k l/ł m n o/ø p q r s š z ž t u v w õ ä ö ü x y þ '.self::GREEK.' '.self::CYRILLIC,
        'en' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'es' => 'a b c d/đ/ð e f g h i ı j k l/ł m n ñ o/ø p q r s t u v w x y z þ '.self::GREEK.' '.self::CYRILLIC,
        'fr' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'hr' => 'a b c č ć d/ð dž đ e f g h i ı j k l/ł lj m n nj o/ø p q r s š t u v w x y z ž þ '.self::CYRILLIC.' '.self::GREEK,
        'it' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'lv' => 'a ā b c č d/đ/ð e ē f g ģ h i y ī ı j k ķ l/ł ļ m n ņ o/ø ō p q r s š t u ū v w x z ž þ '.self::GREEK.' '.self::CYRILLIC,
        'lt' => 'a/ą b c č d/đ/ð e/ę/ė f g h i/į/y ı j k l/ł m n o/ø p q r s š t u/ų/ū v w x z ž þ '.self::GREEK.' '.self::CYRILLIC,
        'hu' => 'a b c cs d/đ/ð dz dzs e f g gy h i ı j k l/ł ly m n ny o/ø ö/ő p q r s sz t ty u ü/ű v w x y z zs þ '.self::GREEK.' '.self::CYRILLIC,
        'nl' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'nb' => 'a b c d/đ/ð e f g h i ı j k l/ł m n o p q r s t u v w x y/ü/ű z æ/ä/ę ø/ö/ő/œ å/aa/aå '.self::GREEK.' '.self::CYRILLIC,
        'pl' => 'a ą b c ć d/đ/ð e ę f g h i ı j k l ł m n ń o/ø ó p q r s ś t u v w x y z ź ż þ '.self::GREEK.' '.self::CYRILLIC,
        'pt' => self::LATIN.' '.self::GREEK.' '.self::CYRILLIC,
        'ro' => 'a ă â b c d/đ/ð e f g h i î ı j k l/ł m n o/ø p q r s ş=ș t ţ=ț u v w x y z þ '.self::GREEK.' '.self::CYRILLIC,
        'sk' => 'a ä b c č d/đ/ð e f g h ch i ı j k l/ł m n o/ø ô p q r ř s š t u v w x y z ž þ '.self::GREEK.' '.self::CYRILLIC,
        'sl' => 'a b c č ć d/ð đ e f g h i ı j k l/ł m n o/ø p q r s š t u v w x y z ž þ '.self::GREEK.' '.self::CYRILLIC,
        // Serbian ships in Latin script and ICU still ranks the Cyrillic
        // alphabet first for it, with и and й one letter; the desktop orders a
        // Serbian list that way, so the phone beside it has to as well.
        'sr' => 'а б в г/ґ д ђ е є ж з и і ј к л љ м н њ о п р с т ћ у ф х ц ч џ ш щ ъ ы ь э ю я '.self::LATIN.' '.self::GREEK,
        'fi' => 'a b c d/đ/ð e f g h i ı j k l/ł m n o p q r s t u v w x y/ü z þ å ä/æ ö/ø '.self::GREEK.' '.self::CYRILLIC,
        'sv' => 'a b c d/đ/ð e f g h i ı j k l/ł m n o p q r s t u v w x y/ü/ű z å ä/æ/ę ö/ø/ő/œ/ô '.self::GREEK.' '.self::CYRILLIC,
        'tr' => 'a b c ç d/đ/ð e f g ğ h ı i j k l/ł m n o/ø ö p q r s ş t u ü v w x y z þ '.self::GREEK.' '.self::CYRILLIC,
        'el' => self::GREEK.' '.self::LATIN.' '.self::CYRILLIC,
        'bg' => self::CYRILLIC.' '.self::LATIN.' '.self::GREEK,
        'uk' => 'а б в г ґ д ђ е є ж з и і ї й ј к л љ м н њ о п р с т ћ у ф х ц ч џ ш щ ъ ы ь э ю я '.self::LATIN.' '.self::GREEK,
    ];

    // Above every accent and below every letter a reader's own alphabet
    // lists: ICU files a spelled-out ligature after the plain spelling of the
    // same letters, so "Oeuvre" precedes "Œuvre".
    private const int LIGATURE_WEIGHT = 34;

    // One letter of the reader's own alphabet outranks every accent on the
    // letter before it, and carries its own accents inside its own step:
    // Lithuanian files i, í, ī, į, y, ý in exactly that order.
    private const int VARIANT_STEP = 100;

    // "dzs" is the longest letter any shipped alphabet spells with more than
    // one character.
    private const int LONGEST_DIGRAPH = 3;

    // A sort asks for the same name's key n·log n times and the picker it
    // sorts is bounded by what a household types; the cap is there so a
    // long-lived desktop process cannot grow one entry per name ever seen.
    private const int KEY_MEMO_LIMIT = 4096;

    /**
     * @var array<string, Collator|null>
     */
    private static array $collators = [];

    /**
     * @var array<string, array{ranks: array<string, int>, accents: array<string, int>, widths: list<int>}>
     */
    private static array $alphabets = [];

    /**
     * @var array<string, string>
     */
    private static array $keys = [];

    public static function compare(string $a, string $b): int
    {
        $collator = self::collator();

        if (! $collator instanceof Collator) {
            return self::compareWithoutIcu($a, $b);
        }

        $order = $collator->compare($a, $b);

        // compare() answers false on a collation failure; treating that as
        // "equal" keeps the sort stable rather than ordering on junk.
        return $order === false ? 0 : $order;
    }

    // Public for the reason Money::formatWithoutIcu() is: this is the arm both
    // phones take, and a host that has ICU data cannot reach it by accident,
    // so an order nothing can call is an order nothing checks.
    public static function compareWithoutIcu(string $a, string $b): int
    {
        $locale = Locale::tryFrom(self::locale()) ?? Locale::En;

        return self::sortKey($a, $locale) <=> self::sortKey($b, $locale);
    }

    // Memoised per locale because building an ICU collator is far dearer than
    // a comparison and a sort asks for one n·log n times. The mobile build's
    // ext-intl carries English-only ICU data, so every other locale throws on
    // device; Error, because no ext-intl at all raises "Collator not found".
    private static function collator(): ?Collator
    {
        $locale = self::locale();

        if (! array_key_exists($locale, self::$collators)) {
            try {
                $collator = Collator::create($locale);
                // Digits read as numbers, not characters, so "Trip 10" follows
                // "Trip 2" — what two of the comparators replaced here already
                // did through strnatcasecmp, and what a reader expects anyway.
                $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
                self::$collators[$locale] = $collator;
            } catch (IntlException|Error) {
                self::$collators[$locale] = null;
            }
        }

        return self::$collators[$locale];
    }

    private static function sortKey(string $label, Locale $locale): string
    {
        $memo = $locale->value."\u{001F}".$label;

        if (isset(self::$keys[$memo])) {
            return self::$keys[$memo];
        }

        if (count(self::$keys) >= self::KEY_MEMO_LIMIT) {
            self::$keys = [];
        }

        return self::$keys[$memo] = self::buildKey($label, $locale);
    }

    // Four keys end to end, each separated by a byte below anything a key can
    // hold, so a name that is another's prefix still sorts first and the
    // letters are exhausted before the accents are, and the accents before
    // the capitals: the three levels ICU compares on, in its order.
    private static function buildKey(string $label, Locale $locale): string
    {
        $alphabet = self::alphabet($locale);
        $folded = self::fold($label, $locale, $alphabet['ranks']);
        $letters = [];
        $accents = [];
        $offset = 0;

        while ($offset < count($folded)) {
            [$letter, $accent] = self::token($folded, $offset, $alphabet);
            $letters[] = $letter;
            $accents[] = $accent;
        }

        return implode("\u{0001}", $letters)
            ."\u{0000}".implode("\u{0001}", $accents)
            ."\u{0000}".implode('', array_column($folded, 2))
            ."\u{0000}".implode('', array_column($folded, 0));
    }

    /**
     * @param  list<array{string, int, string}>  $folded
     * @param  array{ranks: array<string, int>, accents: array<string, int>, widths: list<int>}  $alphabet
     * @return array{string, string}
     */
    private static function token(array $folded, int &$offset, array $alphabet): array
    {
        foreach ($alphabet['widths'] as $width) {
            $slice = array_slice($folded, $offset, $width);
            $variant = implode('', array_column($slice, 0));
            $rank = $alphabet['ranks'][$variant] ?? null;

            if ($rank !== null && count($slice) === $width) {
                $offset += $width;

                return ['2'.sprintf('%04d', $rank), sprintf('%03d', self::accentOf($slice, $alphabet['accents'][$variant]))];
            }
        }

        $digits = self::digitRun($folded, $offset);

        if ($digits !== null) {
            return [$digits, '000'];
        }

        $character = $folded[$offset][0];
        $offset++;

        return [self::unknownToken($character), '000'];
    }

    // mb_ord returns false on an invalid sequence and the analyser's stub says
    // int, so the narrowing is only expressible once the value is taken as mixed.
    private static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    // A letter outside the three scripts the tables carry files after every
    // letter the reader's own alphabet knows, in codepoint order — the one
    // place a transcribed table cannot follow ICU, and the reason the parity
    // test says which scripts it covers.

    private static function unknownToken(string $character): string
    {
        $codepoint = self::intOrZero(mb_ord($character));

        if (preg_match('/^\p{L}$/u', $character) === 1) {
            return '3'.sprintf('%06d', $codepoint);
        }

        $position = mb_strpos(self::PUNCTUATION, $character);

        return '0'.sprintf('%06d', $position === false ? self::UNLISTED_PUNCTUATION + $codepoint : $position);
    }

    // "Backer" before "Bäcker" before "Bæcker": an accent the alphabet folded
    // away ranks inside the step of the letter it folded onto, so a letter the
    // alphabet does list still outranks every accent on the one before it.
    /**
     * @param  list<array{string, int, string}>  $slice
     */
    private static function accentOf(array $slice, int $variantIndex): int
    {
        $mark = 0;
        foreach ($slice as [, $weight]) {
            if ($weight > 0) {
                $mark = $weight;

                break;
            }
        }

        if ($variantIndex === 0) {
            return $mark;
        }

        return (self::STROKE_WEIGHT[implode('', array_column($slice, 0))] ?? self::VARIANT_STEP * $variantIndex) + $mark;
    }

    /**
     * @param  list<array{string, int, string}>  $folded
     */
    private static function digitRun(array $folded, int &$offset): ?string
    {
        $run = '';
        while (isset($folded[$offset + strlen($run)]) && ctype_digit($folded[$offset + strlen($run)][0])) {
            $run .= $folded[$offset + strlen($run)][0];
        }

        if ($run === '') {
            return null;
        }

        $offset += strlen($run);
        $significant = ltrim($run, '0');
        $significant = $significant === '' ? '0' : $significant;

        return '1'.sprintf('%03d', strlen($significant)).$significant;
    }

    /**
     * @param  array<string, int>  $ranks
     * @return list<array{string, int, string}>
     */
    private static function fold(string $label, Locale $locale, array $ranks): array
    {
        $composed = Normalizer::normalize($label, Normalizer::FORM_C);
        $text = is_string($composed) ? $composed : $label;

        $folded = [];
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($characters === false ? [] : $characters as $original) {
            // A mark with no precomposed form survives normalisation on its
            // own; it belongs to the letter before it, and left standing it
            // would be a letter of its own sorting ahead of every digit.
            if (preg_match(self::COMBINING_MARKS, $original) === 1) {
                $last = count($folded) - 1;
                if ($last >= 0 && $folded[$last][1] === 0) {
                    $folded[$last][1] = self::markWeight($original);
                }

                continue;
            }

            foreach (self::foldLetter($original, $locale, $ranks) as $entry) {
                $folded[] = $entry;
            }
        }

        return $locale === Locale::Hu ? self::undoubleDigraphs($folded, $ranks) : $folded;
    }

    // Lower-cased, then reduced to the letters the reader's alphabet knows:
    // a letter it does not list gives up its accents to the base it
    // decomposes to, and a ligature it does not list spells itself out, so
    // one character in is one entry out only when the alphabet lists it.
    /**
     * @param  array<string, int>  $ranks
     * @return list<array{string, int, string}>
     */
    private static function foldLetter(string $original, Locale $locale, array $ranks): array
    {
        $lowered = mb_strtolower($original);

        // Danish is the one shipped language whose reader expects the
        // capital first; Turkish is the one whose dot is the letter.
        $case = ($lowered === $original) === ($locale !== Locale::Da) ? '0' : '1';
        $character = $locale === Locale::Tr ? mb_strtolower(self::turkishI($original)) : $lowered;

        if (isset($ranks[$character])) {
            return [[$character, 0, $case]];
        }

        if (isset(self::EXPANSIONS[$character])) {
            $spelled = [];
            $weight = self::LIGATURE_WEIGHT;
            foreach (str_split(self::EXPANSIONS[$character]) as $letter) {
                $spelled[] = [$letter, $weight, $case];
                $weight = 0;
            }

            return $spelled;
        }

        [$base, $mark] = self::stripMarks($character);

        // "1º Andar" is an o written small: ICU reads the same letter and
        // separates the two the way it separates a capital from a small.
        if (Normalizer::normalize($character, Normalizer::FORM_KC) !== $character) {
            $case = self::COMPATIBILITY_CASE;
        }

        $stripped = [];
        $baseCharacters = preg_split('//u', $base, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($baseCharacters === false ? [] : $baseCharacters as $letter) {
            $stripped[] = [$letter, $mark, $case];
            $mark = 0;
        }

        return $stripped;
    }

    // Hungarian writes a doubled digraph with its first letter written once:
    // "lly" is two ly, and read as l followed by ly it filed "amellyel" ahead
    // of "amely". Longest first, so "ddzs" is two dzs and not two dz; an
    // accent on the single letter breaks the pair, so "l̄ly" stays l̄ then ly.
    /**
     * @param  list<array{string, int, string}>  $folded
     * @param  array<string, int>  $ranks
     * @return list<array{string, int, string}>
     */
    private static function undoubleDigraphs(array $folded, array $ranks): array
    {
        $out = [];

        for ($index = 0; $index < count($folded); $index++) {
            for ($width = self::LONGEST_DIGRAPH; $folded[$index][1] === 0 && $width >= 2; $width--) {
                $digraph = array_slice($folded, $index + 1, $width);
                $spelled = implode('', array_column($digraph, 0));

                if (count($digraph) === $width
                    && isset($ranks[$spelled])
                    && str_starts_with($spelled, $folded[$index][0])) {
                    $out = array_merge($out, $digraph, $digraph);
                    $index += $width;

                    continue 2;
                }
            }

            $out[] = $folded[$index];
        }

        return $out;
    }

    // Turkish keeps the dotless I a letter of its own under an accent too:
    // ICU files Í with ı, not with i, so the dot has to be taken off the base
    // rather than off the composed character.
    private static function turkishI(string $character): string
    {
        if ($character === 'İ') {
            return 'i';
        }

        $decomposed = Normalizer::normalize($character, Normalizer::FORM_D);
        $base = is_string($decomposed) ? $decomposed : $character;

        return str_starts_with($base, 'I') ? 'ı'.substr($base, 1) : $character;
    }

    /**
     * @return array{string, int}
     */
    private static function stripMarks(string $character): array
    {
        $decomposed = Normalizer::normalize($character, Normalizer::FORM_KD);
        $base = is_string($decomposed) ? $decomposed : $character;
        $stripped = preg_replace(self::COMBINING_MARKS, '', $base) ?? $base;
        $marks = preg_match(self::COMBINING_MARKS, $base, $found) === 1 ? $found[0] : '';

        return [$stripped === '' ? $character : $stripped, $marks === '' ? 0 : self::markWeight($marks)];
    }

    private static function markWeight(string $marks): int
    {
        foreach (self::MARK_ORDER as $rank => $mark) {
            if (str_contains($marks, $mark)) {
                return 2 * ($rank + 1);
            }
        }

        return self::UNLISTED_MARK;
    }

    /**
     * @return array{ranks: array<string, int>, accents: array<string, int>, widths: list<int>}
     */
    private static function alphabet(Locale $locale): array
    {
        if (! isset(self::$alphabets[$locale->value])) {
            $ranks = [];
            $accents = [];
            $widths = [];

            foreach (explode(' ', self::ORDER[$locale->value]) as $rank => $element) {
                foreach (explode('/', $element) as $index => $variant) {
                    foreach (explode('=', $variant) as $spelling) {
                        $ranks[$spelling] = $rank;
                        $accents[$spelling] = $index;
                        $widths[mb_strlen($spelling)] = true;
                    }
                }
            }

            krsort($widths);
            self::$alphabets[$locale->value] = ['ranks' => $ranks, 'accents' => $accents, 'widths' => array_keys($widths)];
        }

        return self::$alphabets[$locale->value];
    }

    private static function locale(): string
    {
        return Container::getInstance()->make(Translator::class)->getLocale();
    }
}
