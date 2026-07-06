<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One parsed source category (YNAB4 Master/Sub Category pair, nYNAB's
 * combined "Category Group/Category" column, or an Actual `categories` row
 * read through `v_categories`).
 *
 * `sourceExternalId` is the source-native identifier when one exists
 * (Actual's UUID); for YNAB4/nYNAB (which carry no stable per-category id in
 * their CSV export) the parser synthesizes a deterministic natural key
 * (normalized `"<group>/<name>"`) per CONTEXT.md D-10's natural-key fallback.
 * `sourceGroupName` is the category-group label (YNAB4's `Master Category`,
 * nYNAB's group half of the combined column, Actual's `category_groups.name`)
 * — null only if a source genuinely has no grouping concept. `kind` is
 * `'income'|'expense'`, derived from the source's own income/expense
 * signal (YNAB4/nYNAB: the "Income" master-category convention; Actual:
 * `categories.is_income`).
 */
final class MigrationCategoryDto extends Data
{
    public function __construct(
        public readonly string $sourceExternalId,
        public readonly string $name,
        public readonly ?string $sourceGroupName,
        public readonly ?string $parentSourceExternalId,
        /** 'income' | 'expense' */
        public readonly string $kind,
    ) {}
}
