<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Modules\OpenBanking\Internal\Enums\FetchStop;

// How far a paginated fetch actually got. The generator that walks the pages
// returns one of these, so the caller reads the ending from the walk rather
// than inferring it from the rows it happened to receive.
final readonly class FetchWalk
{
    private function __construct(
        public FetchStop $stop,
        public int $pages,
        public int $rows,
    ) {}

    public static function exhausted(int $pages = 0, int $rows = 0): self
    {
        return new self(FetchStop::Exhausted, $pages, $rows);
    }

    public static function stoppedAt(FetchStop $stop, int $pages, int $rows): self
    {
        return new self($stop, $pages, $rows);
    }

    public function isComplete(): bool
    {
        return $this->stop === FetchStop::Exhausted;
    }
}
