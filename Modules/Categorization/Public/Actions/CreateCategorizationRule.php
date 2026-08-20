<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Categorization\Public\Actions\Concerns\NormalisesRuleInput;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

final class CreateCategorizationRule
{
    use NormalisesRuleInput;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    // $input->conditions/$input->actions are untrusted, caller-supplied
    // arrays; normalizeInput validates every element field-by-field before
    // use, never assuming a shape.
    public function __invoke(User $user, RuleInput $input): int
    {
        ['conditions' => $normalizedConditions, 'actions' => $normalizedActions] = self::normalizeInput($this->db, $input, $user);

        $now = $this->clock->now()->toDateTimeString();
        $db = $this->db;

        try {
            return $db->connection()->transaction(static function () use ($db, $normalizedConditions, $normalizedActions, $user, $input, $now): int {
                $connection = $db->connection();

                $ruleId = $connection->table('categorization_rules')->insertGetId([
                    'user_id' => $user->id,
                    'priority' => $input->priority,
                    'combinator' => $input->combinator,
                    'active' => $input->active,
                    'notes' => $input->notes,
                    'hits_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($normalizedConditions as $condition) {
                    $connection->table('rule_conditions')->insert([
                        'rule_id' => $ruleId,
                        'field' => $condition['field'],
                        'op' => $condition['op'],
                        'value_type' => $condition['value_type'],
                        'value' => $condition['value'],
                        'value2' => $condition['value2'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($normalizedActions as $action) {
                    $connection->table('rule_actions')->insert([
                        'rule_id' => $ruleId,
                        'position' => $action['position'],
                        'type' => $action['type'],
                        'payload' => self::encodePayload($action['payload']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $ruleId;
            });
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'value' => self::duplicateMessage(),
                ]);
            }
            throw $e;
        }
    }
}
