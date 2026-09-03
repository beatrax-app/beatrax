<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\DependentRowCascade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DeleteCategorizationRule
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private DependentRowCascade $cascade,
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

        /** @var list<object> $dependents */
        $dependents = [];

        $connection->transaction(function () use ($connection, $ruleId, $user, &$dependents): void {
            $dependents = $this->cascade->delete('categorization_rules', $ruleId, $user->id);

            $connection
                ->table('categorization_rules')
                ->where('id', $ruleId)
                ->where('user_id', $user->id)
                ->delete();
        });

        foreach ($dependents as $event) {
            $this->events->dispatch($event);
        }
    }
}
