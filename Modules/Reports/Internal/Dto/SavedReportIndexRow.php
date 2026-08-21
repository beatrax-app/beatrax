<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

final readonly class SavedReportIndexRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $summary,
        public bool $pinned,
        public ?int $pinOrder,
    ) {}
}
