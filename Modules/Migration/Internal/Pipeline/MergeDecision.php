<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Public\Dto\ConflictDto;
use Modules\Migration\Public\Enums\MigrationEntityType;

final class MergeDecision
{
    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    public function __construct(
        public readonly array $applies,
        public readonly array $conflicts,
    ) {}

    /**
     * @return list<string>
     */
    public function conflictedBudgetAssignmentKeys(): array
    {
        $keys = [];
        foreach ($this->conflicts as $conflict) {
            if ($conflict->entityType === MigrationEntityType::BudgetAssignment->value && $conflict->sourceExternalId !== null) {
                $keys[] = $conflict->sourceExternalId;
            }
        }

        return $keys;
    }
}
