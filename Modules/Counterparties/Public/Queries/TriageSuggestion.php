<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final readonly class TriageSuggestion
{
    public function __construct(
        public string $suggestedCounterpartyName,
        public string $confidence,
        public string $reasoning,
    ) {}
}
