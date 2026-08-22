<?php

declare(strict_types=1);

use Modules\Core\Public\Support\LocaleCollator;

// The ICU-less arm is not a rare fallback: the mobile build ships ext-intl with
// English-only data, so every non-English locale throws and both phones sort
// through it. It folded accents with iconv //TRANSLIT, whose tables belong to
// the C library — so an Android reader and an iPhone reader in the same
// household could see the same list in two different orders.

/**
 * ICU answers for a locale it does not know by falling back to the root one,
 * so naming a nonsense locale does NOT reach the arm under test — it silently
 * sorts through a real collator and the assertions pass either way. The memo
 * is the only honest door: seeding it with the null this class stores when
 * building a collator throws is exactly the state a phone is in.
 *
 * @param  callable(): int  $compare
 */
function withoutACollator(callable $compare): int
{
    $locale = 'zz-no-icu';
    $translator = app('translator');
    $previous = $translator->getLocale();

    $memo = new ReflectionProperty(LocaleCollator::class, 'collators');
    $before = $memo->getValue();
    $memo->setValue(null, [$locale => null]);
    $translator->setLocale($locale);

    try {
        return $compare();
    } finally {
        $translator->setLocale($previous);
        $memo->setValue(null, $before);
    }
}

it('folds an accent onto its base letter without asking the C library', function (): void {
    // Aa < Ab: true only if the accent is folded away. Byte order puts every
    // accented name after Z, and a libc that answers "?" puts it before A.
    $order = withoutACollator(static fn (): int => LocaleCollator::compare('Ångström AB', 'Anders BV'));

    expect($order)->toBeGreaterThan(0);
});

// macOS folded every Greek name to the empty string, so they all compared
// equal to each other and sorted arbitrarily among themselves — and a name
// whose fold is empty also compares equal to a name that is genuinely empty.
it('tells two Greek names apart rather than folding both to nothing', function (): void {
    $order = withoutACollator(static fn (): int => LocaleCollator::compare('Ωμέγα', 'Άλφα'));

    expect($order)->not->toBe(0);
});

// The F of "Færge" became a question mark on macOS, which sorts among the
// punctuation rather than under F. Æ is a letter, and it folds to AE.
it('folds a ligature to both its letters instead of losing one', function (): void {
    expect(withoutACollator(static fn (): int => LocaleCollator::compare('Ærø Bakkerij', 'Afzet BV')))
        ->toBeLessThan(0);

    expect(withoutACollator(static fn (): int => LocaleCollator::compare('Ærø Bakkerij', 'Adema BV')))
        ->toBeGreaterThan(0);
});

it('orders a Dutch list the way its reader reads it, with no collator at all', function (): void {
    $names = ['Zeta Zaken', 'Émile Fleurs', 'Ångström AB', 'Alpha BV'];

    withoutACollator(static function () use (&$names): int {
        usort($names, static fn (string $a, string $b): int => LocaleCollator::compare($a, $b));

        return 0;
    });

    expect($names)->toBe(['Alpha BV', 'Ångström AB', 'Émile Fleurs', 'Zeta Zaken']);
});
