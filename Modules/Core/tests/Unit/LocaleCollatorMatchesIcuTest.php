<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\LocaleCollator;

// The desktop sorts through ICU and both phones sort through the transcribed
// alphabets, and the same household reads both. This is the invariant that
// makes the second one worth having: the two orders are the same order.
//
// It covers the three scripts the tables carry — Latin, Greek and Cyrillic —
// which is every script the twenty-six shipped languages are written in. A
// letter outside them files after the reader's own alphabet in codepoint
// order, which ICU does not do; that boundary is named in
// .docs/features/core/sorting-without-icu.md rather than asserted here.

/**
 * Real reader-facing words in the reader's own script: every distinct word in
 * that language's own Core translations, which is where the country picker's
 * thirty-three names live, plus the endonyms the language switcher lists.
 *
 * @return list<string>
 */
function readerWords(string $locale): array
{
    $words = [];

    foreach (glob(base_path('Modules/Core/Resources/lang/'.$locale.'/*.php')) ?: [] as $file) {
        /** @var array<array-key, mixed> $lines */
        $lines = require $file;
        collectWords($lines, $words);
    }

    foreach (Locale::cases() as $case) {
        $words[$case->label()] = true;
    }

    return array_keys($words);
}

/**
 * @param  array<array-key, mixed>  $lines
 * @param  array<string, true>  $words
 */
function collectWords(array $lines, array &$words): void
{
    foreach ($lines as $line) {
        if (is_array($line)) {
            collectWords($line, $words);

            continue;
        }

        if (! is_string($line)) {
            continue;
        }

        foreach (preg_split('/[^\p{L}\p{M}\p{N}\'\-]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // A token carrying a digit is not a word in anybody's alphabet: the
            // copy embeds a styled <code> element, so the split scrapes out
            // `bg-amber-100` and `px-1` alongside names like `Argon2id`. Their
            // order under NUMERIC_COLLATION is an ICU build's business, and it
            // differs between the macOS and Linux ICUs this suite runs on.
            if (preg_match('/^\p{L}/u', $word) === 1 && preg_match('/\p{N}/u', $word) !== 1) {
                $words[$word] = true;
            }
        }
    }
}

/**
 * The letters this locale's table separates, in its own order. A group joined
 * by `/` is one letter written more than one way, so only its first spelling
 * is a distinct letter.
 *
 * @return list<string>
 */
function alphabetLetters(string $locale): array
{
    /** @var array<string, string> $order */
    $order = (new ReflectionClass(LocaleCollator::class))->getConstant('ORDER');

    $letters = [];

    foreach (preg_split('/\s+/u', $order[$locale] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $group) {
        $letters[] = explode('/', $group)[0];
    }

    return $letters;
}

// Whether the ICU this runtime carries implements the alphabet the table
// describes. Latvian files ā as its own letter after a; an ICU build without
// that tailoring reads ā as an accented a and orders `ārējā` under `are`.
// Comparing word lists against such a build compares against root collation
// wearing the locale's name, which is a different specification and not the
// one the phones transcribe.
function icuKnowsTheAlphabet(string $locale): bool
{
    $probe = Collator::create($locale);

    if (! $probe instanceof Collator) {
        return false;
    }

    // PRIMARY is the strength at which "a different letter" is decided; at
    // any stronger setting an accent alone separates and every build passes.
    $probe->setStrength(Collator::PRIMARY);
    $letters = alphabetLetters($locale);

    for ($i = 1, $count = count($letters); $i < $count; $i++) {
        if ($probe->compare($letters[$i - 1], $letters[$i]) === 0) {
            return false;
        }
    }

    return true;
}

it('orders a reader\'s own words the way ICU orders them, in every shipped language', function (): void {
    $divergent = [];
    $unimplemented = [];

    foreach (Locale::cases() as $locale) {
        if (! icuKnowsTheAlphabet($locale->value)) {
            $unimplemented[] = $locale->value;

            continue;
        }

        app()->make(Translator::class)->setLocale($locale->value);

        $collator = Collator::create($locale->value);
        expect($collator)->toBeInstanceOf(Collator::class);
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        $words = readerWords($locale->value);
        expect(count($words))->toBeGreaterThan(150);

        $withIcu = $words;
        // Where ICU calls two spellings equal any order is legal, so the
        // fallback settles those on both sides; a real disagreement still shows.
        usort($withIcu, static fn (string $a, string $b): int => $collator->compare($a, $b)
            ?: LocaleCollator::compareWithoutIcu($a, $b));

        $withoutIcu = $words;
        usort($withoutIcu, static fn (string $a, string $b): int => LocaleCollator::compareWithoutIcu($a, $b));

        foreach ($withIcu as $position => $word) {
            if (($withoutIcu[$position] ?? null) !== $word) {
                $divergent[] = $locale->value." #{$position}: icu={$word} phone=".($withoutIcu[$position] ?? '-');

                break;
            }
        }
    }

    // A build that implemented nothing would report no divergence at all, so
    // the floor is what stops silence reading as agreement. The names are
    // carried into the message because which languages this runtime cannot
    // check is the thing a reader of a green run would want to know.
    expect(count($unimplemented))->toBeLessThan(count(Locale::cases()) - 5, 'this ICU implements almost no shipped alphabet: '.implode(', ', $unimplemented));

    expect($divergent)->toBe([], 'checked every locale except '.($unimplemented === [] ? 'none' : implode(', ', $unimplemented)));
});

it('carries an alphabet for every shipped language', function (): void {
    // A locale with no table would fall through to English and file a Greek
    // name after Z again, which is the whole of the defect this replaced.
    $order = (new ReflectionClass(LocaleCollator::class))->getConstant('ORDER');

    expect(array_keys($order))->toEqualCanonicalizing(
        array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()),
    );
});
