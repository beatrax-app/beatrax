<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of one `categorization_rules` row.
 *
 * `field` is one of `'merchant'`, `'description'`, `'counterparty'`;
 * `match` is one of `'contains'`, `'equals'`, `'starts_with'`. Both
 * enums are enforced at the database layer via paired BEFORE INSERT /
 * BEFORE UPDATE triggers, so a typo in the rule-creation action fails
 * loud rather than landing a silently-broken rule.
 *
 * `categoryPath` is the flattened breadcrumb (e.g.
 * "Subscriptions / Streaming") joined at read time from the
 * categories tree so the `/rules` table renders without an
 * additional lookup.
 *
 * `hitsCount` is denormalised on the rule row and incremented by
 * `ApplyAutoCategoryStage` whenever the rule fires for an incoming
 * transaction. It surfaces in the `/rules` table column.
 *
 * `active` lets the user disable a rule without deleting it (UX
 * nicety + an audit trail for past hits). `notes` is a free-form
 * memo also surfaced in the rule-form modal.
 */
final class CategorizationRuleDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $field,
        public readonly string $match,
        public readonly string $value,
        public readonly int $categoryId,
        public readonly string $categoryPath,
        public readonly int $hitsCount,
        public readonly bool $active,
        public readonly ?string $notes,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
