<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class EntityMutated
{
    /**
     * @param  string  $table  The op-log table name. Must carry merge rules,
     *                         or the peer quarantines every op built from it.
     * @param  string  $mutationType  'create' | 'edit' | 'delete'.
     * @param  array<string, mixed>  $dirtyFields  Columns the write touched:
     *                                             every column for a create,
     *                                             the changed ones for an
     *                                             edit, empty for a delete.
     */
    public function __construct(
        public string $table,
        public int|string $pk,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
