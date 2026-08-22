<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;

// counterparties.slug is the firstOrCreate key, so the slugifier is a stored
// identifier, not a formatting choice. These cases are the ones where the two
// slugifiers in this repo disagree on ASCII alone; swapping one for the other
// would fork every already-stored merchant into a second row.

it('keeps a dotted abbreviation apart the way the stored slugs already do', function (): void {
    expect(CounterpartySlugResolver::slugify('Coolblue B.V.'))->toBe('coolblue-b-v')
        ->and(UniqueSlug::slugify('Coolblue B.V.', 'counterparty'))->toBe('coolblue-bv');
});

it('separates on a slash where the framework slugifier deletes it', function (): void {
    expect(CounterpartySlugResolver::slugify('Shop 24/7'))->toBe('shop-24-7')
        ->and(UniqueSlug::slugify('Shop 24/7', 'counterparty'))->toBe('shop-247');
});

it('collapses a run of separators to a single dash and trims the ends', function (): void {
    expect(CounterpartySlugResolver::slugify('  --Bol.com-- '))->toBe('bol-com');
});

it('falls back to the literal counterparty when nothing survives', function (): void {
    expect(CounterpartySlugResolver::slugify('🎉🎉'))->toBe('counterparty')
        ->and(CounterpartySlugResolver::slugify('&&&'))->toBe('counterparty')
        ->and(CounterpartySlugResolver::slugify(''))->toBe('counterparty');
});

it('cuts the base to the 128 characters the slug column declares', function (): void {
    $long = str_repeat('ab', 200);

    expect(strlen(CounterpartySlugResolver::slugify($long)))->toBe(128);
});

it('leaves a name that is already short of the cut untouched', function (): void {
    $exact = str_repeat('a', 128);

    expect(CounterpartySlugResolver::slugify($exact))->toBe($exact);
});

// expand() asks one question of every non-ASCII character — romanise it, or
// make it a word break — and a character that changes sides re-slugs every
// stored merchant carrying it. The two spellings of that question are proved
// equal over every codepoint rather than over a handful of names.
it('answers the romanise-or-separate question identically for every codepoint', function (): void {
    $disagreements = [];

    for ($codepoint = 0x80; $codepoint <= 0x10FFFF; $codepoint++) {
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            continue;
        }

        $char = mb_chr($codepoint, 'UTF-8');
        if ($char === false) {
            continue;
        }

        if (preg_match('/\p{Latin}|\P{L}/u', $char) !== preg_match('/[\p{Latin}\P{L}]/u', $char)) {
            $disagreements[] = 'U+'.strtoupper(dechex($codepoint));
        }
    }

    expect($disagreements)->toBe([]);
});

// A second implementation of the documented rule, reading every table off the
// class so only the decision under test is written out here. Two spellings of
// one algorithm that agree on 63,456 codepoints are one algorithm.
function slugifiedTheOtherWay(string $value): string
{
    $resolver = new ReflectionClass(CounterpartySlugResolver::class);
    /** @var array<string, string> $gaps */
    $gaps = $resolver->getConstant('TRANSLITERATION_GAPS');
    $invisible = (string) $resolver->getConstant('INVISIBLE');
    $separator = (string) $resolver->getConstant('SEPARATOR');
    $fallback = (string) $resolver->getConstant('FALLBACK');
    $cut = (int) $resolver->getConstant('SLUG_COLUMN_MAX_LENGTH');

    $substituted = strtr($value, $gaps);
    $decomposed = Normalizer::normalize($substituted, Normalizer::FORM_KD);
    $base = is_string($decomposed) ? $decomposed : $substituted;
    $withoutMarks = preg_replace($invisible, '', $base) ?? $base;

    $ascii = preg_replace_callback(
        '/[^\x00-\x7F]/u',
        static function (array $match) use ($separator): string {
            /** @var array<int, string> $match */
            $spelled = preg_match('/\p{Latin}|\P{L}/u', $match[0]) === 1 ? Str::ascii($match[0]) : '';

            return $spelled === '' ? $separator : $spelled;
        },
        $withoutMarks,
    ) ?? '';

    $cleaned = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '';
    $trimmed = trim($cleaned, '-');

    return $trimmed === '' ? $fallback : substr($trimmed, 0, $cut);
}

it('derives the same slug as that second implementation across the whole BMP', function (): void {
    $forks = [];

    for ($codepoint = 0x20; $codepoint <= 0xFFFF; $codepoint++) {
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            continue;
        }

        $char = mb_chr($codepoint, 'UTF-8');
        if ($char === false) {
            continue;
        }

        // Between two ASCII letters, so a character that became a separator is
        // visible in the result rather than trimmed off an end.
        $name = 'a'.$char.'z';

        if (CounterpartySlugResolver::slugify($name) !== slugifiedTheOtherWay($name)) {
            $forks[] = 'U+'.strtoupper(dechex($codepoint));
        }
    }

    expect($forks)->toBe([]);
});
