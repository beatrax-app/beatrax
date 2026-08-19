<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class GoalMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'.
     * @param  array<string, mixed>  $dirtyFields  Columns the write touched.
     *                                             Every column for a create,
     *                                             empty for a delete.
     */
    public function __construct(
        public int $goalId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
