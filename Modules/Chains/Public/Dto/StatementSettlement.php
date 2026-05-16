<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Return shape from `CardStatementStateMachine::applySettlement()`.
 *
 * Carries the post-transition snapshot so resolvers can stamp the
 * unaccounted delta + new state into `chain_link.evidence` without a
 * second read of the card_statements row.
 *
 *   - `$previousOpenMinor` — the open balance before the delta applied
 *   - `$newOpenMinor` — the open balance after the delta applied
 *   - `$newState` — one of `open`, `partially_settled`, `settled`,
 *     `overpaid` (or the previous state when the delta was a no-op)
 */
final class StatementSettlement extends Data
{
    public function __construct(
        public readonly int $statementId,
        public readonly int $previousOpenMinor,
        public readonly int $newOpenMinor,
        public readonly string $newState,
    ) {}
}
