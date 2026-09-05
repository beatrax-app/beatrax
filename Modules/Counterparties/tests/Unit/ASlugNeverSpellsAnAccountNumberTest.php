<?php

declare(strict_types=1);

use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;

// The shape half of the rule, asked of the STORED slug so the migration can
// ask it with no decryption key held. The checksum half is exercised through
// the resolver in PrivacyDefaultsTest, because it needs the validator.
it('recognises a compact IBAN of any country as an account identifier', function (string $slug): void {
    expect(CounterpartySlugResolver::spellsAnAccountIdentifier($slug))->toBeTrue();
})->with([
    'nl91abna0417164300',
    'nl02abna0123456789',
    'be68539007547034',
    'de89370400440532013000',
    'gb29nwbk60161331926819',
    'lu89751000135104200e',
    'mt84malt011000012345mtlcast001s',
]);

it('recognises a bare account number long enough not to be a name', function (): void {
    expect(CounterpartySlugResolver::spellsAnAccountIdentifier('0417164300'))->toBeTrue()
        ->and(CounterpartySlugResolver::spellsAnAccountIdentifier('123456789'))->toBeTrue();
});

// The cost of a false positive is a counterparty routed under `unnamed`
// instead of its own name, so the negatives are the half worth pinning: every
// one of these is a slug this repository already produces or stores.
it('leaves a name that merely carries letters and digits alone', function (string $slug): void {
    expect(CounterpartySlugResolver::spellsAnAccountIdentifier($slug))->toBeFalse();
})->with([
    'bol-com',
    'bol-2',
    'coolblue-b-v',
    'shop-24-7',
    'maria-van-buren',
    'jeroen-de-vries',
    'asn-bank',
    'international-card-services',
    'self-asn-checking',
    'belastingdienst',
    'unknown',
    'counterparty',
    'unnamed',
    'unnamed-2',
    'nl-12-design-studio-amsterdam',
    'h-m-2024-collection',
    'nl02',
    '12345678',
]);

it('names its own opaque base, so the migration and the walk cannot spell it two ways', function (): void {
    expect(CounterpartySlugResolver::OPAQUE_BASE)->toBe('unnamed')
        ->and(CounterpartySlugResolver::spellsAnAccountIdentifier(CounterpartySlugResolver::OPAQUE_BASE))->toBeFalse();
});
