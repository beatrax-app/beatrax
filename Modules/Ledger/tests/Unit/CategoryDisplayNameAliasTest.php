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

it('emits an unaliased select list a bare fromRow() can read back', function (): void {
    expect(CategoryDisplayName::bareColumns())->toBe(['name', 'slug', 'name_is_default'])
        ->and(CategoryDisplayName::bareColumns('c'))->toBe(['c.name', 'c.slug', 'c.name_is_default']);

    $row = new stdClass;
    foreach (CategoryDisplayName::bareColumns() as $column) {
        $row->{$column} = match ($column) {
            'name' => 'Groceries',
            'name_is_default' => 1,
            default => 'groceries',
        };
    }

    expect(CategoryDisplayName::fromRow($row))->toBe('Groceries');
});

it('refuses the empty alias columns() cannot express rather than emitting _name', function (): void {
    expect(static fn (): array => CategoryDisplayName::columns('c', ''))
        ->toThrow(InvalidArgumentException::class, 'needs a non-empty alias');
});

// Both column lists are generated from PARTS, so a fourth part cannot reach one
// shape of select and miss the other — the drift that put ten hand-written
// lists in front of a seam that already existed.
it('keeps the aliased and bare column lists naming the same parts', function (): void {
    $aliased = array_map(
        static fn (string $column): string => explode(' as ', $column)[0],
        CategoryDisplayName::columns('c'),
    );

    expect($aliased)->toBe(CategoryDisplayName::bareColumns('c'));
});
