<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// Funding-chain summary surfaced on a merchant counterparty's Overview
// tab; feeds <x-counterparties::chain-flow>'s compact horizontal render.
// The cross-module Chains lookup that materialises this DTO is wired in
// a follow-up plan — this shape keeps the consuming view's type stable.
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
