<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

final readonly class EnvelopeAssignmentMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     *                                             Empty for delete ops.
     */
    public function __construct(
        public int $assignmentId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
