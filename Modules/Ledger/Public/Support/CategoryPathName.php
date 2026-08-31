<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use stdClass;

// Where CategoryDisplayName answers "what is this row called", this answers
// "which one of them is it" — the group in front of the leaf. Four screens had
// grown their own copy of that concatenation; one spelling of a category is
// what stops /budgets and /reports naming the same envelope differently.
final class CategoryPathName
{
    // Not '/': 'Rent / Mortgage' and 'Cloud / Software' are seeded category
    // names, so 'Housing / Rent / Mortgage' reads as three levels, not two.
    public const string SEPARATOR = ' › ';

    // A parent that resolved to nothing is the same answer as no parent: a row
    // whose parent_id points outside what the reader may see arrives here as
    // null from CategoryDisplayName::fromRow(), and an empty prefix would put a
    // leading separator on the leaf.
    public static function join(?string $parent, string $leaf): string
    {
        return $parent === null || $parent === '' ? $leaf : $parent.self::SEPARATOR.$leaf;
    }

    /**
     * @param  list<string>  $parts
     */
    public static function fromParts(array $parts): string
    {
        return implode(self::SEPARATOR, $parts);
    }

    // One level up, for a read that already joins `categories` once and wants
    // the group beside the leaf without a second round trip. The visibility
    // predicate is the point of having this in one place: the copy that omitted
    // it printed another tenant's category name in front of the reader's own.
    public static function joinParent(Builder $query, int $userId, string $childAlias, string $parentAlias): Builder
    {
        return $query->leftJoin('categories as '.$parentAlias, static function (JoinClause $join) use ($userId, $childAlias, $parentAlias): void {
            $join->on($childAlias.'.parent_id', '=', $parentAlias.'.id')
                ->where(static function (Builder $q) use ($userId, $parentAlias): void {
                    $q->whereNull($parentAlias.'.user_id')->orWhere($parentAlias.'.user_id', $userId);
                });
        });
    }

    /**
     * @return list<string>
     */
    public static function columns(string $childTable, string $parentTable, string $alias = 'category'): array
    {
        return [
            ...CategoryDisplayName::columns($childTable, $alias),
            ...CategoryDisplayName::columns($parentTable, $alias.'_parent'),
        ];
    }

    // Qualifying a leaf with its group runs out when the groups are named alike
    // too, or when both rows are top level: nothing further up the tree can
    // differ. Numbered lowest id first, so the seeded row a reader has always
    // called this keeps its bare name and the one that arrived later moves.
    /**
     * @param  array<int, string>  $pathsById
     * @return array<int, string>
     */
    public static function distinct(array $pathsById): array
    {
        ksort($pathsById);

        /** @var array<string, true> $taken */
        $taken = [];
        $distinct = [];

        foreach ($pathsById as $id => $path) {
            $label = $path;
            $ordinal = 1;

            // A category genuinely named "Groceries (2)" would otherwise take
            // the label this hands the second "Groceries", so the walk keeps
            // going rather than assuming the first suffix is free.
            while (isset($taken[$label])) {
                $ordinal++;
                $label = Lang::get('ledger::common.duplicate_path', ['path' => $path, 'number' => Fmt::number($ordinal)]);
            }

            $taken[$label] = true;
            $distinct[$id] = $label;
        }

        return $distinct;
    }

    // Null when the row carries no category at all, which is a left-joined
    // transaction with none assigned — not the same answer as a leaf whose
    // parent is out of view, which resolves to the bare leaf.
    public static function fromRow(stdClass $row, string $alias = 'category'): ?string
    {
        $leaf = CategoryDisplayName::fromRow($row, $alias);

        return $leaf === null ? null : self::join(CategoryDisplayName::fromRow($row, $alias.'_parent'), $leaf);
    }
}
