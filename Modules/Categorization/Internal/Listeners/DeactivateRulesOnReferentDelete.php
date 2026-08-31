<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class DeactivateRulesOnReferentDelete
{
    use CoercesScalars;

    private const string COUNTERPARTIES_TABLE = 'counterparties';

    private const string DELETE_MUTATION = 'delete';

    public function __construct(private DatabaseManager $db) {}

    public function handleCategoryDeleting(Model $category): void
    {
        $this->deactivate(
            userId: self::toNullableInt($category->getAttribute('user_id')),
            actionType: ActionType::Category->value,
            payloadKey: 'category_id',
            referentId: self::toInt($category->getAttribute('id')),
        );
    }

    public function handleCounterpartyDeleting(Model $counterparty): void
    {
        $this->deactivate(
            userId: self::toNullableInt($counterparty->getAttribute('user_id')),
            actionType: ActionType::Counterparty->value,
            payloadKey: 'counterparty_id',
            referentId: self::toInt($counterparty->getAttribute('id')),
        );
    }

    // The garbage collector is the only thing in production that deletes a
    // counterparty, and it deletes through the query builder, which fires no
    // model event: the arm above never ran on a real install. This reads the
    // row-level announcement that same write already makes.
    /**
     * @link ../../../../.docs/features/categorization/architecture.md#app-level-referential-integrity
     */
    public function handleCounterpartyPruned(EntityMutated $event): void
    {
        if ($event->table !== self::COUNTERPARTIES_TABLE || $event->mutationType !== self::DELETE_MUTATION) {
            return;
        }

        $this->deactivate(
            userId: $event->userId,
            actionType: ActionType::Counterparty->value,
            payloadKey: 'counterparty_id',
            referentId: self::toInt($event->pk),
        );
    }

    private function deactivate(?int $userId, string $actionType, string $payloadKey, int $referentId): void
    {
        if ($userId === null) {
            // Global (unowned, user_id IS NULL) referents have no single
            // user to scope the UPDATE by; this is a defensive no-op.
            return;
        }

        $this->db->connection()->statement(
            'UPDATE categorization_rules SET active = 0 WHERE user_id = ? AND id IN ('
                .'SELECT rule_id FROM rule_actions WHERE type = ? AND json_extract(payload, ?) = ?'
                .')',
            [$userId, $actionType, '$.'.$payloadKey, $referentId],
        );
    }

    private static function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
