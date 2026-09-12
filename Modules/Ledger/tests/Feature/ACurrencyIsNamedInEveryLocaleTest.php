<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\Support\CurrencyDisplayName;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

// Every currency the bundled snapshot can price is choosable, and a picker in
// twenty-six languages needs a name for each of them in each of those
// languages. ICU knows them all, so nobody translates a currency here — but the
// mobile build bundles ICU with English-only locale data, which answers a Dutch
// lookup with the English name and no error at all. The names are therefore
// transcribed into the tree at generation time, and this holds the transcript
// to what ICU says.
// @link ../../../../.docs/features/ledger/currency-names.md

/** @return array<string, array<string, string>> locale => code => name, as the tree carries it */
function carriedCurrencyNames(): array
{
    /** @var array<string, array<string, string>> $names */
    $names = json_decode(
        (string) file_get_contents(base_path('Modules/Ledger/Resources/currency-names.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $names;
}

/** @return list<string> every code the bundled snapshot can put a price on, the euro it quotes against included */
function priceableCurrencyCodes(): array
{
    /** @var array{rates: array<string, string>} $snapshot */
    $snapshot = json_decode(
        (string) file_get_contents(base_path('Modules/FX/Resources/rates-snapshot.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $codes = array_keys($snapshot['rates']);
    $codes[] = 'EUR';
    $codes = array_values(array_unique($codes));
    sort($codes);

    return $codes;
}

/** what ICU's own data for this locale declares, with no fallback up the chain to English */
function icuDeclaredCurrencyName(string $locale, string $code): ?string
{
    $bundle = ResourceBundle::create(match ($locale) {
        // ICU keeps Norwegian under `no` and Serbian's Latin script under
        // `sr_Latn`; the app's own codes for the two reach neither.
        Locale::Nb->value => 'no',
        Locale::Sr->value => 'sr_Latn',
        default => $locale,
    }, 'ICUDATA-curr', false);

    $entry = $bundle === null ? null : (($bundle['Currencies'] ?? null)[$code] ?? null);
    $name = $entry === null ? null : ($entry[1] ?? null);

    return is_string($name) && $name !== '' ? $name : null;
}

afterEach(function (): void {
    app()->setLocale(Locale::DEFAULT);
});

it('names every currency the bundled snapshot can price, in every locale the app ships', function (): void {
    $names = carriedCurrencyNames();
    $codes = priceableCurrencyCodes();

    expect($codes)->toHaveCount(31);

    $missing = [];
    foreach (Locale::cases() as $locale) {
        foreach ($codes as $code) {
            $name = $names[$locale->value][$code] ?? null;
            if (! is_string($name) || trim($name) === '') {
                $missing[] = $locale->value.'.'.$code;
            }
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'A currency the install can price has no name in a language it ships, so its picker option',
        'reads as a bare code for that reader. Re-run scripts/generate_currency_names.php:',
        '  '.implode("\n  ", $missing),
    ]));
});

// Held against English and required to DIFFER. Asserting the Dutch name alone
// passes with English on the screen, which is exactly what a missing locale
// produces: the data this replaces fell back to a stored English word, and ICU
// itself falls back to English for a locale whose data is absent.
it('names a currency in the reader language rather than in English', function (string $code, string $dutch): void {
    $names = carriedCurrencyNames();

    expect($names[Locale::Nl->value][$code])->toBe($dutch)
        ->and($names[Locale::Nl->value][$code])->not->toBe($names[Locale::DEFAULT][$code]);
})->with([
    ['SEK', 'Zweedse kroon'],
    ['USD', 'Amerikaanse dollar'],
    ['GBP', 'Britse pond'],
    ['ISK', 'IJslandse kroon'],
    ['CHF', 'Zwitserse frank'],
]);

it('offers the reader the name in the locale they are reading in', function (): void {
    app()->setLocale(Locale::Nl->value);
    $dutch = CurrencyDisplayName::forCode('SEK');

    app()->setLocale(Locale::DEFAULT);
    $english = CurrencyDisplayName::forCode('SEK');

    expect($dutch)->toBe('Zweedse kroon')
        ->and($english)->toBe('Swedish Krona')
        ->and($dutch)->not->toBe($english);
});

// A code outside the priceable set can reach a picker: an importer stamps the
// statement's own denomination on the account it mints. It reads as the code
// rather than as somebody else's language.
it('falls back to the code itself, never to another reader language', function (): void {
    app()->setLocale(Locale::Nl->value);

    expect(CurrencyDisplayName::forCode('BHD'))->toBe('BHD');
});

// Serbian ships in Latin script, and ICU's `sr` is Cyrillic. A lookup by the
// app's own locale code would have been the wrong script rather than a missing
// name, which is the failure that reads as a working one.
it('writes Serbian in the script it ships in', function (): void {
    $serbian = carriedCurrencyNames()[Locale::Sr->value];

    $cyrillic = array_keys(array_filter(
        $serbian,
        static fn (string $name): bool => preg_match('/(?=\p{L})\p{Cyrillic}/u', $name) === 1,
    ));

    expect($cyrillic)->toBe([], 'Serbian currency names in Cyrillic: '.implode(', ', $cyrillic));
});

// ICU holds Norwegian under `no` and leaves `nb` carrying a parent pointer and
// nothing else, so asking for `nb` returns no name at all — and a resolver that
// answered the absence with English would read as one that worked.
it('names a currency in Norwegian', function (): void {
    $names = carriedCurrencyNames();

    expect($names[Locale::Nb->value]['SEK'])->toBe('svenske kroner')
        ->and($names[Locale::Nb->value]['SEK'])->not->toBe($names[Locale::DEFAULT]['SEK']);
});

// Not an equality check against this host's ICU. CLDR rewords currencies
// between releases — an older one names CNY "yuan" where a newer writes "yuan
// renminbi", and Serbian moved "Evro" to "evro" — so a transcript generated
// once and committed cannot be held to whatever ICU the runner happens to
// carry without an unrelated system upgrade reddening the build. What survives
// every version is the property the transcript exists for: where ICU says a
// language has its own word for a currency, the transcript must not be
// answering that language in English.
it('carries the reader language wherever ICU says that language has its own word', function (): void {
    // The positive control. Every comparison below passes on a host whose ICU
    // data is filtered to English if the transcript were English too, and this
    // is the one assertion that fails instead of agreeing with itself.
    expect(icuDeclaredCurrencyName(Locale::Nl->value, 'SEK'))
        ->not->toBe(icuDeclaredCurrencyName(Locale::DEFAULT, 'SEK'))
        ->and(icuDeclaredCurrencyName(Locale::Nl->value, 'SEK'))
        ->toBeString('This host cannot name a currency in Dutch, so it cannot check the transcript.');

    $names = carriedCurrencyNames();
    $englishTranscript = $names[Locale::DEFAULT];

    $leftInEnglish = [];
    foreach ($names as $locale => $byCode) {
        if ($locale === Locale::DEFAULT) {
            continue;
        }

        foreach ($byCode as $code => $carried) {
            $declared = icuDeclaredCurrencyName($locale, $code);
            $english = icuDeclaredCurrencyName(Locale::DEFAULT, $code);

            if ($declared === null || $english === null || $declared === $english) {
                continue;
            }

            if ($carried === ($englishTranscript[$code] ?? null)) {
                $leftInEnglish[] = $locale.'.'.$code.': the transcript says '.var_export($carried, true)
                    .', which is its English, where ICU writes '.var_export($declared, true);
            }
        }
    }

    expect($leftInEnglish)->toBe([], implode("\n", [
        'A language ICU gives its own word for is reading in English. Re-run',
        'scripts/generate_currency_names.php on a host with full ICU locale data:',
        '  '.implode("\n  ", $leftInEnglish),
    ]));
});

// A currency's minor unit is the other thing that must not be assumed: the same
// integer is 148.30 ISK and 1.48 EUR, and both 1.234 dinars and 1234 of them.
// The scale is pinned to ISO 4217 as the money library carries it, which is
// what every stored integer in the tree was written against.
it('scales a currency at the minor unit its stored integers were written against', function (string $code, int $decimals): void {
    expect(CurrencyScale::decimals($code))->toBe($decimals);
})->with([
    ['ISK', 0],
    ['JPY', 0],
    ['KRW', 0],
    ['EUR', 2],
    ['SEK', 2],
    // ICU 78 dropped the forint and the rupiah to zero decimals where ICU 77
    // and ISO 4217 give them two. The scale a stored integer means cannot move
    // when a host's system library is upgraded, so it is not ICU's to decide.
    ['HUF', 2],
    ['IDR', 2],
    ['BHD', 3],
    ['KWD', 3],
    ['TND', 3],
]);

// The library formats through ICU, so the guarantee above is only real while
// the rendered figure carries the scale the amount was stored at rather than
// the one the host's ICU data would have chosen for the code.
it('renders a figure at its own scale whatever the host ICU has since decided', function (string $code): void {
    app()->setLocale(Locale::DEFAULT);

    expect(Money::ofMinor(123450, $code)->format())->toEndWith('.50');
})->with(['HUF', 'IDR', 'EUR']);

it('records the same minor unit on every currency row it seeds', function (): void {
    $rows = DB::table('currencies')->orderBy('code')->get(['code', 'minor_unit']);

    expect($rows)->toHaveCount(count(priceableCurrencyCodes()));

    $disagreed = [];
    foreach ($rows as $row) {
        /** @var stdClass $row */
        $code = (string) $row->code;
        $stored = (int) $row->minor_unit;

        if ($stored !== CurrencyScale::decimals($code)) {
            $disagreed[] = $code.': the row says '.$stored.' decimals, the app scales it at '.CurrencyScale::decimals($code);
        }
    }

    expect($disagreed)->toBe([], implode("\n", $disagreed));
});
