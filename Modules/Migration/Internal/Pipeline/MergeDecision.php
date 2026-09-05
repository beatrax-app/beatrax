<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Internal\Dto\ConflictDto;
use Modules\Migration\Internal\Dto\UnreconciledFieldDto;

final readonly class MergeDecision
{
    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     * @param  list<UnreconciledFieldDto>  $unreconciled  fields the merge could not judge, reported to the reader and never applied
     */
    public function __construct(
        public array $applies,
        public array $conflicts,
        public array $unreconciled = [],
    ) {}
}
