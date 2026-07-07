<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * A single-field 3-way-merge conflict (Req 10, CONTEXT.md D-11/D-12/D-13):
 * the SOURCE value changed since the entity's last import AND the LOCAL
 * (beatrax-side) value also changed since then, so neither can be applied
 * automatically without risking silently overwriting a user edit.
 *
 * `localValue` / `sourceValue` / `baselineValue` are intentionally `mixed` —
 * the same `ConflictDto` shape covers a budget-assignment minor-unit int, a
 * transaction description string, or a category-name string, so the
 * reconciliation UI can render any of them behind one generic "keep
 * mine / take theirs" affordance.
 *
 * `resolution` defaults to `'keep_local'` per D-14's documented default:
 * an unresolved conflict never silently applies the source value. Per
 * RESEARCH.md's 3-way-merge algorithm, resolving a conflict (even via this
 * default) advances the entity's baseline to the resolved value so the
 * identical conflict does not re-surface on the next reconciliation run.
 *
 * `currency` is set only for a money-shaped field (`budgeted_minor` /
 * `amount_minor`) — an ISO 4217 code so the preview UI can format the three
 * values as currency (13.5-HUMAN-UAT.md Test 3a) instead of raw minor-unit
 * integers. NULL for a non-money field (category/account/transaction name
 * or description conflicts).
 */
final class ConflictDto extends Data
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?string $sourceExternalId,
        public readonly string $fieldName,
        public readonly mixed $localValue,
        public readonly mixed $sourceValue,
        public readonly mixed $baselineValue,
        public readonly ?string $currency = null,
        public readonly string $resolution = 'keep_local',
    ) {}
}
