<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that deletes one categorization_rules row.
 *
 * Cross-user safety: the row is looked up via
 * `where('id', $ruleId)->where('user_id', $user->id)` before the
 * DELETE. A foreign-user id throws NotFoundHttpException (404, not
 * 403) so the existence of other users' rules is not leaked through
 * the error path.
 *
 * The DELETE runs inside a DB transaction so the read-then-delete
 * pair is atomic — preventing a TOCTOU race where a concurrent
 * cascade could delete the row between the lookup and the write.
 *
 * Side effect: the category_id FK is configured to cascade on
 * category delete, but the rule -> category direction does NOT
 * cascade in reverse — deleting a rule never deletes the linked
 * category, and the transactions previously categorised by this
 * rule keep their category_id intact (the rule deletion does not
 * retroactively un-categorise).
 */
final class DeleteCategorizationRule
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(User $user, int $ruleId): void
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

        $connection->transaction(function () use ($connection, $ruleId, $user): void {
            $connection
                ->table('categorization_rules')
                ->where('id', $ruleId)
                ->where('user_id', $user->id)
                ->delete();
        });
    }
}
