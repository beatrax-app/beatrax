<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
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
