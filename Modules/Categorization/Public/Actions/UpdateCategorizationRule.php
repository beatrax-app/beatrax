<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Categorization\Public\Actions\Concerns\NormalisesRuleInput;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class UpdateCategorizationRule
{
    use NormalisesRuleInput;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    // $input->conditions/$input->actions are untrusted, caller-supplied
    // arrays; normalizeInput validates every element field-by-field. A
    // condition/action `id` (present only for a row that already exists) tells
    // the diff below whether to update in place or insert.
    public function __invoke(User $user, int $ruleId, RuleInput $input): int
    {
        $connection = $this->db->connection();

        $row = $connection
            ->table('categorization_rules')
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        ['conditions' => $normalizedConditions, 'actions' => $normalizedActions] = self::normalizeInput($this->db, $input, $user);

        $now = $this->clock->now()->toDateTimeString();

        try {
            return $connection->transaction(static function () use ($connection, $ruleId, $user, $input, $normalizedConditions, $normalizedActions, $now): int {
                $connection
                    ->table('categorization_rules')
                    ->where('id', $ruleId)
                    ->where('user_id', $user->id)
                    ->update([
                        'priority' => $input->priority,
                        'combinator' => $input->combinator,
                        'active' => $input->active,
                        'notes' => $input->notes,
                        'updated_at' => $now,
                    ]);

                self::diffConditions($connection, $ruleId, $normalizedConditions, $now);
                self::diffActions($connection, $ruleId, $normalizedActions, $now);

                return $ruleId;
            });
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'value' => self::DUPLICATE_MESSAGE,
                ]);
            }
            throw $e;
        }
    }

    /**
     * @param  list<array{id: ?int, field: string, op: string, value_type: string, value: string, value2: ?string}>  $conditions
     */
    private static function diffConditions(ConnectionInterface $connection, int $ruleId, array $conditions, string $now): void
    {
        /** @var list<int> $existingIds */
        $existingIds = $connection->table('rule_conditions')
            ->where('rule_id', $ruleId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $incomingIds = [];
        foreach ($conditions as $condition) {
            $id = $condition['id'];
            if ($id !== null && in_array($id, $existingIds, true)) {
                $incomingIds[] = $id;
            }
        }

        foreach ($conditions as $condition) {
            $id = $condition['id'];
            $fields = [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'value_type' => $condition['value_type'],
                'value' => $condition['value'],
                'value2' => $condition['value2'],
            ];

            if ($id !== null && in_array($id, $existingIds, true)) {
                $connection->table('rule_conditions')
                    ->where('id', $id)
                    ->where('rule_id', $ruleId)
                    ->update($fields + ['updated_at' => $now]);
            } else {
                $connection->table('rule_conditions')->insert($fields + [
                    'rule_id' => $ruleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $removedIds = array_diff($existingIds, $incomingIds);
        if ($removedIds !== []) {
            $connection->table('rule_conditions')
                ->where('rule_id', $ruleId)
                ->whereIn('id', $removedIds)
                ->delete();
        }
    }

    /**
     * @param  list<array{id: ?int, position: int, type: string, payload: array<string, mixed>}>  $actions
     */
    private static function diffActions(ConnectionInterface $connection, int $ruleId, array $actions, string $now): void
    {
        /** @var list<int> $existingIds */
        $existingIds = $connection->table('rule_actions')
            ->where('rule_id', $ruleId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $incomingIds = [];
        foreach ($actions as $action) {
            $id = $action['id'];
            if ($id !== null && in_array($id, $existingIds, true)) {
                $incomingIds[] = $id;
            }
        }

        foreach ($actions as $action) {
            $id = $action['id'];
            $fields = [
                'position' => $action['position'],
                'type' => $action['type'],
                'payload' => self::encodePayload($action['payload']),
            ];

            if ($id !== null && in_array($id, $existingIds, true)) {
                $connection->table('rule_actions')
                    ->where('id', $id)
                    ->where('rule_id', $ruleId)
                    ->update($fields + ['updated_at' => $now]);
            } else {
                $connection->table('rule_actions')->insert($fields + [
                    'rule_id' => $ruleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $removedIds = array_diff($existingIds, $incomingIds);
        if ($removedIds !== []) {
            $connection->table('rule_actions')
                ->where('rule_id', $ruleId)
                ->whereIn('id', $removedIds)
                ->delete();
        }
    }
}
