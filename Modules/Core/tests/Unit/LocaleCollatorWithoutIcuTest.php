<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\LocaleCollator;

// The ICU-less arm is not a rare fallback: the mobile build ships ext-intl with
// English-only data, so every non-English locale throws and both phones sort
// through it. It transliterated Greek and Cyrillic into Latin and then sorted
// in the Latin alphabet, which is an order no reader of either recognises.

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

/**
 * @param  list<string>  $names
 * @return list<string>
 */
function sortedWithoutIcu(string $locale, array $names): array
{
    app()->make(Translator::class)->setLocale($locale);
    usort($names, static fn (string $a, string $b): int => LocaleCollator::compareWithoutIcu($a, $b));

    return $names;
}

/**
 * @return list<string>
 */
function countryNames(string $locale): array
{
    /** @var array{country: array{countries: array<string, string>}} $settings */
    $settings = require base_path('Modules/Core/Resources/lang/'.$locale.'/settings.php');

    return array_values($settings['country']['countries']);
}

it('routes compare() to the alphabet when no collator can be built', function (): void {
    // Aa < Ab: true only if the accent is folded away. Byte order puts every
    // accented name after Z, and a libc that answers "?" puts it before A.
    expect(withoutACollator(static fn (): int => LocaleCollator::compare('Ångström AB', 'Anders BV')))
        ->toBeGreaterThan(0);
});

// Φ is the twenty-first letter of the Greek alphabet and the transliterating
// fold filed Φινλανδία eighth, ahead of Γαλλία and Γερμανία, whose Γ is third.
it('orders a Greek list in the Greek alphabet, not in Latin', function (): void {
    $ordered = sortedWithoutIcu(Locale::El->value, ['Φινλανδία', 'Γαλλία', 'Γερμανία', 'Δανία', 'Ελλάδα', 'Αυστρία', 'Βέλγιο']);

    expect($ordered)->toBe(['Αυστρία', 'Βέλγιο', 'Γαλλία', 'Γερμανία', 'Δανία', 'Ελλάδα', 'Φινλανδία']);
});

// Ч is the twenty-fifth Cyrillic letter and was landing fourth.
it('orders a Bulgarian list in the Cyrillic alphabet, not in Latin', function (): void {
    $ordered = sortedWithoutIcu(Locale::Bg->value, ['Чехия', 'Дания', 'Финландия', 'Германия', 'Австрия', 'България']);

    expect($ordered)->toBe(['Австрия', 'България', 'Германия', 'Дания', 'Финландия', 'Чехия']);
});

it('files a letter where the reader files it and not where its bytes fall', function (string $locale, array $names, array $expected): void {
    expect(sortedWithoutIcu($locale, $names))->toBe($expected);
})->with([
    // Czech reads "ch" as one letter, after H — the fold filed it under C.
    'cs digraph' => ['cs', ['Chorvatsko', 'Island', 'Kypr', 'Dánsko'], ['Dánsko', 'Chorvatsko', 'Island', 'Kypr']],
    // Slovak has the same digraph, and ä of its own between A and B.
    'sk digraph' => ['sk', ['Chorvátsko', 'Island', 'Dánsko'], ['Dánsko', 'Chorvátsko', 'Island']],
    // Ø and Å are letters of their own at the end, not decorated O and A.
    'da last letters' => ['da', ['Åland', 'Østrig', 'Belgien', 'Zypern'], ['Belgien', 'Zypern', 'Østrig', 'Åland']],
    'nb last letters' => ['nb', ['Åland', 'Østerrike', 'Belgia', 'Zypern'], ['Belgia', 'Zypern', 'Østerrike', 'Åland']],
    // Swedish and Finnish end Å Ä Ö; German reads Ö as a decorated O.
    'sv last letters' => ['sv', ['Österrike', 'Ängland', 'Åland', 'Zypern'], ['Zypern', 'Åland', 'Ängland', 'Österrike']],
    'de umlaut is an o' => ['de', ['Österreich', 'Polen', 'Norwegen'], ['Norwegen', 'Österreich', 'Polen']],
    // Ł is its own letter after L, and Ó its own after O.
    'pl own letters' => ['pl', ['Łotwa', 'Litwa', 'Malta', 'Luksemburg'], ['Litwa', 'Luksemburg', 'Łotwa', 'Malta']],
    // Estonian puts Z between S and T, and Õ Ä Ö Ü after W.
    'et z before t' => ['et', ['Taani', 'Zypern', 'Soome', 'Õlu'], ['Soome', 'Zypern', 'Taani', 'Õlu']],
    // Turkish reads the dotless I as its own letter, before the dotted one.
    'tr dotless i' => ['tr', ['İzlanda', 'Irak', 'Isveç'], ['Irak', 'Isveç', 'İzlanda']],
    // Hungarian reads "cs" and "sz" as single letters.
    'hu digraphs' => ['hu', ['Csehország', 'Ciprus', 'Dánia'], ['Ciprus', 'Csehország', 'Dánia']],
    // Lithuanian files its own letters after every accent on the base.
    'lt own letters' => ['lt', ['Ylgas', 'Įlgas', 'Ilgas', 'Ìlgas', 'Īlgas'], ['Ilgas', 'Ìlgas', 'Īlgas', 'Įlgas', 'Ylgas']],
]);

// macOS folded every Greek name to the empty string, so they all compared
// equal to each other and sorted arbitrarily among themselves — and a name
// whose fold is empty also compares equal to a name that is genuinely empty.
it('tells two Greek names apart and puts them in the Greek order', function (): void {
    app()->make(Translator::class)->setLocale(Locale::El->value);

    expect(LocaleCollator::compareWithoutIcu('Ωμέγα', 'Άλφα'))->toBeGreaterThan(0)
        ->and(LocaleCollator::compareWithoutIcu('Άλφα', 'Βήτα'))->toBeLessThan(0);
});

// The F of "Færge" became a question mark on macOS, which sorts among the
// punctuation rather than under F. Æ is a letter, and it folds to AE.
it('folds a ligature to both its letters instead of losing one', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(LocaleCollator::compareWithoutIcu('Ærø Bakkerij', 'Afzet BV'))->toBeLessThan(0)
        ->and(LocaleCollator::compareWithoutIcu('Ærø Bakkerij', 'Adema BV'))->toBeGreaterThan(0);
});

// Danish spells Å as "Aa" too, and reads the pair as the single last letter.
it('reads the Danish Aa as the letter it spells', function (): void {
    expect(sortedWithoutIcu(Locale::Da->value, ['Aabenraa', 'Zeeland', 'Aarhus', 'Bornholm']))
        ->toBe(['Bornholm', 'Zeeland', 'Aabenraa', 'Aarhus']);
});

it('reads a run of digits as a number, the way strnatcasecmp did', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(LocaleCollator::compareWithoutIcu('Trip 2', 'Trip 10'))->toBeLessThan(0)
        ->and(LocaleCollator::compareWithoutIcu('Trip 2', 'Trip 10'))->toBe(strnatcasecmp('Trip 2', 'Trip 10'));
});

// Every call site spells the tiebreak `compare(...) ?: $a->id <=> $b->id`, so
// equal names have to answer a falsy 0 for the id half to be reached at all.
it('answers zero for two equal names so the call site tiebreak still runs', function (): void {
    app()->make(Translator::class)->setLocale(Locale::Nl->value);

    expect(LocaleCollator::compareWithoutIcu('Groceries', 'Groceries'))->toBe(0);
});

it('orders a name that is another name\'s prefix first', function (): void {
    expect(sortedWithoutIcu(Locale::Nl->value, ['Albert Heijn XL', 'Albert', 'Albert Heijn']))
        ->toBe(['Albert', 'Albert Heijn', 'Albert Heijn XL']);
});
