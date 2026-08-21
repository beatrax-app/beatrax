<?php

declare(strict_types=1);

use Modules\Ledger\Public\Support\CategoryDisplayName;

// columns() defaults its alias to 'category' and fromRow() defaults its own to
// ''. The two defaults do not compose, and the failure used to be a silent
// null — a blank cell on a budget screen with nothing raised anywhere. Every
// shipped call site pairs them correctly; these pin the shape, not the sites.

function categoryAliasRow(string $prefix): stdClass
{
    $row = new stdClass;
    $row->{$prefix.'name'} = 'Groceries';
    $row->{$prefix.'slug'} = 'groceries';
    $row->{$prefix.'name_is_default'} = 1;

    return $row;
}

it('refuses a row selected under a different alias rather than returning a blank name', function (): void {
    $selectedByColumns = categoryAliasRow('category_');

    expect(static fn (): ?string => CategoryDisplayName::fromRow($selectedByColumns))
        ->toThrow(InvalidArgumentException::class, 'the row carries no name, no slug, no name_is_default');
});

it('names the alias it looked under when only some of the three are missing', function (): void {
    $row = categoryAliasRow('');
    unset($row->slug);

    expect(static fn (): ?string => CategoryDisplayName::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'the row carries no slug');
});

it('refuses the same mismatch through isDefaultRow', function (): void {
    $selectedByColumns = categoryAliasRow('category_');

    expect(static fn (): bool => CategoryDisplayName::isDefaultRow($selectedByColumns))
        ->toThrow(InvalidArgumentException::class);
});

it('still answers null for a row whose category columns are present and NULL', function (): void {
    $unjoined = new stdClass;
    $unjoined->category_name = null;
    $unjoined->category_slug = null;
    $unjoined->category_name_is_default = null;

    expect(CategoryDisplayName::fromRow($unjoined, 'category'))->toBeNull()
        ->and(CategoryDisplayName::isDefaultRow($unjoined, 'category'))->toBeFalse();
});

it('reads the aliases columns() actually emits', function (): void {
    expect(CategoryDisplayName::columns('c'))->toBe([
        'c.name as category_name',
        'c.slug as category_slug',
        'c.name_is_default as category_name_is_default',
    ]);

    expect(CategoryDisplayName::fromRow(categoryAliasRow('category_'), 'category'))->toBe('Groceries');
});
