<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class GoalContributionMutated
{
    /**
     * @param  string  $mutationType  'create' | 'delete' — an attribution exists or it does not.
     * @param  array<string, mixed>  $dirtyFields  Identity columns of the new row.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $contributionId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
