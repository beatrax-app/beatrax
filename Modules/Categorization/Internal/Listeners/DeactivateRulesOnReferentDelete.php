<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

/**
 * App-level referential-integrity guard replacing the FK cascade lost
 * by D-03's JSON-payload design (RESEARCH.md Pitfall 2 / T-13.4-09).
 * `rule_actions.payload` embeds `category_id`/`counterparty_id` as an
 * opaque JSON value — there is no FK, so deleting a referenced
 * category or counterparty would otherwise leave an active rule
 * pointing at a dangling id.
 *
 * Deactivates the whole RULE (not just the offending `rule_actions`
 * row) because a rule missing one of its >=1 required actions would
 * violate the D-06 structural invariant ("a rule always has at least
 * one condition and one action").
 *
 * Registered against the Ledger `Category` model's and the
 * Counterparties `Counterparty` model's Eloquent `deleting` event via
 * the framework's `eloquent.deleting: {FQCN}` wildcard event name (a
 * plain string, wired in `CategorizationServiceProvider::boot()`) —
 * NOT by importing either model class. Neither module exposes a
 * Public delete action or delete event today (confirmed by grep
 * across both modules' Public namespaces), so this is the sanctioned
 * fallback per the plan's own `<interfaces>` contract; using the
 * string-keyed wildcard event (and typing the handler's parameter as
 * the framework's own `Illuminate\Database\Eloquent\Model`, never the
 * concrete Ledger/Counterparties class) keeps this module's coupling
 * to those modules at the same "raw table name" level every other
 * cross-module read in this module already uses (see
 * `CreateCategorizationRule::assertCategoryVisible`/
 * `assertCounterpartyVisible`), rather than a hard class dependency
 * on another module's model internals.
 *
 * Every UPDATE is scoped by the deleted row's own `user_id` and
 * touches only `categorization_rules.active` — never `rule_actions`
 * or `rule_conditions` directly, and never dispatches any Sync
 * op-log capture event (rule-authoring writes stay outside Sync's
 * scope for 13.4 — RESEARCH.md Pitfall 5(b)).
 */
final class DeactivateRulesOnReferentDelete
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function handleCategoryDeleting(Model $category): void
    {
        $this->deactivate(
            userId: self::toNullableInt($category->getAttribute('user_id')),
            actionType: 'category',
            payloadKey: 'category_id',
            referentId: self::toInt($category->getAttribute('id')),
        );
    }

    public function handleCounterpartyDeleting(Model $counterparty): void
    {
        $this->deactivate(
            userId: self::toNullableInt($counterparty->getAttribute('user_id')),
            actionType: 'counterparty',
            payloadKey: 'counterparty_id',
            referentId: self::toInt($counterparty->getAttribute('id')),
        );
    }

    private function deactivate(?int $userId, string $actionType, string $payloadKey, int $referentId): void
    {
        if ($userId === null) {
            // Global (unowned, user_id IS NULL) referents have no single
            // user to scope the UPDATE by. Neither module exposes any
            // delete path for a global row today (no Category/Counterparty
            // delete action exists in the app at all), so this is a
            // defensive no-op rather than a silently-unhandled case.
            return;
        }

        $this->db->connection()->statement(
            'UPDATE categorization_rules SET active = 0 WHERE user_id = ? AND id IN ('
                .'SELECT rule_id FROM rule_actions WHERE type = ? AND json_extract(payload, ?) = ?'
                .')',
            [$userId, $actionType, '$.'.$payloadKey, $referentId],
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
