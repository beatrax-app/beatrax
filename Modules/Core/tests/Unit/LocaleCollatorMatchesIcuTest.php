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
            if (preg_match('/^\p{L}/u', $word) === 1) {
                $words[$word] = true;
            }
        }
    }
}

it('orders a reader\'s own words the way ICU orders them, in every shipped language', function (): void {
    $divergent = [];

    foreach (Locale::cases() as $locale) {
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

    expect($divergent)->toBe([]);
});

it('carries an alphabet for every shipped language', function (): void {
    // A locale with no table would fall through to English and file a Greek
    // name after Z again, which is the whole of the defect this replaced.
    $order = (new ReflectionClass(LocaleCollator::class))->getConstant('ORDER');

    expect(array_keys($order))->toEqualCanonicalizing(
        array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()),
    );
});
