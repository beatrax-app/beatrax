<?php

declare(strict_types=1);

use Modules\Core\Public\Support\UniqueSlug;

// The three resolvers that share this walk each own a unique(user_id, slug)
// index. The numbers below are the ones all three returned before they were
// merged: the base when it is free, then -2, -3, -4 with no gap and no -1.

/**
 * @param  list<string>  $taken
 */
function slugTakenPredicate(array $taken): Closure
{
    return static fn (string $slug): bool => ! in_array($slug, $taken, true);
}

it('hands back the base when nothing holds it', function (): void {
    expect(UniqueSlug::walk('bol', slugTakenPredicate([])))->toBe('bol');
});

it('starts the suffix at 2, never at 1', function (): void {
    expect(UniqueSlug::walk('bol', slugTakenPredicate(['bol'])))->toBe('bol-2');
});

it('keeps walking past every taken suffix without skipping one', function (): void {
    expect(UniqueSlug::walk('bol', slugTakenPredicate(['bol', 'bol-2', 'bol-3'])))->toBe('bol-4');
});

it('takes the first hole rather than the highest suffix', function (): void {
    expect(UniqueSlug::walk('bol', slugTakenPredicate(['bol', 'bol-3'])))->toBe('bol-2');
});

it('asks the predicate about the base before it asks about any suffix', function (): void {
    $asked = [];
    $isFree = static function (string $slug) use (&$asked): bool {
        $asked[] = $slug;

        return $slug === 'bol-2';
    };

    expect(UniqueSlug::walk('bol', $isFree))->toBe('bol-2')
        ->and($asked)->toBe(['bol', 'bol-2']);
});

it('lets a free base short-circuit the predicate after one call', function (): void {
    $calls = 0;
    $isFree = static function (string $slug) use (&$calls): bool {
        $calls++;

        return true;
    };

    expect(UniqueSlug::walk('bol', $isFree))->toBe('bol')
        ->and($calls)->toBe(1);
});

it('kebab-cases a plain name', function (): void {
    expect(UniqueSlug::slugify('ASN Betaalrekening', 'account'))->toBe('asn-betaalrekening');
});

it('returns the callers fallback when the name slugs to nothing', function (): void {
    expect(UniqueSlug::slugify('🎉🎉', 'account'))->toBe('account')
        ->and(UniqueSlug::slugify('&&&', 'item'))->toBe('item')
        ->and(UniqueSlug::slugify('', 'item'))->toBe('item')
        ->and(UniqueSlug::slugify("   \t\n ", 'item'))->toBe('item');
});

it('does not reach for the fallback when anything at all survives', function (): void {
    expect(UniqueSlug::slugify('Café Ambiance', 'account'))->toBe('cafe-ambiance')
        ->and(UniqueSlug::slugify('7', 'account'))->toBe('7');
});
