<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * Funding-chain summary surfaced on a merchant counterparty's Overview
 * tab. Carries the chain's source node sequence (PayPal → ASN, ICS →
 * ASN, etc.) so the `<x-counterparties::chain-flow>` component can
 * render the compact horizontal flow.
 *
 * The cross-module Chains lookup that materialises this DTO lands in
 * plan 17-06c — this shape exists so the consuming Livewire view's
 * type signature is stable across the wiring drop.
 *
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
