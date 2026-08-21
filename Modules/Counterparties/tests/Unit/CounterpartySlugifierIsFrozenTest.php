<?php

declare(strict_types=1);

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
