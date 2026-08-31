<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Internal\Dto\ConflictDto;

final readonly class MergeDecision
{
    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    public function __construct(
        public array $applies,
        public array $conflicts,
    ) {}
}
