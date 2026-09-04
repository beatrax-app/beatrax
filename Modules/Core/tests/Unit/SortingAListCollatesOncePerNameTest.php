<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\LocaleCollator;

// compare() answers one pair, so sorting n names collated n·log n times and
// resolved the translator out of the container on every one of them. sorted()
// keys each name once instead; this is the pin that the cheaper shape is the
// same order, on both the ICU arm and the transcribed one the phones take.

/**
 * @return list<string>
 */
function collationSample(): array
{
    return ['Zeta', 'Ångström', 'Émile', 'Albert Heijn', 'Œuvre', 'Ørsted', 'Trip 10', 'Trip 2',
        'trip 2', 'Æther', 'Jansen-de Vries', 'Jansen & Vries', "d'Anvers", 'Çınar', 'Ümit',
        'zeta', 'Ekoplaza', 'ıstanbul', 'Istanbul', 'Bol.com', '', 'Zoë', 'Zoe'];
}

/**
 * The memo is the only honest door onto the ICU-less arm: naming an unknown
 * locale still reaches a real collator through ICU's root fallback, so the
 * null this class stores when Collator::create() throws is seeded directly.
 *
 * @param  callable(): void  $body
 */
function withCollatorMemo(?array $memo, callable $body): void
{
    $property = new ReflectionProperty(LocaleCollator::class, 'collators');
    $before = $property->getValue();
    $translator = app('translator');
    $previous = $translator->getLocale();

    if ($memo !== null) {
        $property->setValue(null, $memo);
        $translator->setLocale((string) array_key_first($memo));
    }

    try {
        $body();
    } finally {
        $translator->setLocale($previous);
        $property->setValue(null, $before);
    }
}

it('orders a list exactly as the pairwise comparator does, in every shipped locale', function (): void {
    $translator = app('translator');
    $previous = $translator->getLocale();

    foreach (Locale::cases() as $locale) {
        $translator->setLocale($locale->value);

        $pairwise = collationSample();
        usort($pairwise, static fn (string $a, string $b): int => LocaleCollator::compare($a, $b));

        expect(LocaleCollator::sorted(collationSample(), static fn (string $n): string => $n))
            ->toBe($pairwise, "order differs under {$locale->value}");
    }

    $translator->setLocale($previous);
});

it('orders a list the same way on the arm both phones take', function (): void {
    withCollatorMemo(['zz-no-icu' => null], function (): void {
        $pairwise = collationSample();
        usort($pairwise, static fn (string $a, string $b): int => LocaleCollator::compare($a, $b));

        expect(LocaleCollator::sorted(collationSample(), static fn (string $n): string => $n))->toBe($pairwise);
    });
});

// Holding the translator instance rather than resolving it per comparison is
// only safe while the locale is still read off it: a memoised locale string
// would leave a reader who switches language looking at the old alphabet.
it('follows a locale switched after the first sort', function (): void {
    $translator = app('translator');
    $previous = $translator->getLocale();

    $translator->setLocale('en');
    $english = LocaleCollator::sorted(['Ö', 'Z', 'A'], static fn (string $n): string => $n);

    $translator->setLocale('sv');
    $swedish = LocaleCollator::sorted(['Ö', 'Z', 'A'], static fn (string $n): string => $n);

    $translator->setLocale($previous);

    expect($english)->toBe(['A', 'Ö', 'Z'])
        ->and($swedish)->toBe(['A', 'Z', 'Ö']);
});
