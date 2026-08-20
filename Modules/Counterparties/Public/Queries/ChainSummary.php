<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * @phpstan-type ChainNode array{label: string, glyph: string|null}
 */
final readonly class ChainSummary
{
    /**
     * @param  list<array{label: string, glyph: string|null}>  $nodes
     */
    public function __construct(
        public string $headline,
        public array $nodes,
    ) {}
}
