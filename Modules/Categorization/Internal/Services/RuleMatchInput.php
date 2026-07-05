<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * The shape `RuleEngine::match()` evaluates conditions against.
 * Deliberately decoupled from the full `CanonicalTransaction` DTO so
 * the re-apply job (Plan 07) can build one from a persisted
 * `transactions` row without needing an import-pipeline DTO in scope
 * — only the four properties a rule condition can ever reference are
 * carried.
 *
 * `settledAmountMinor` and `postedAt` are always compared against
 * regardless of a condition's `field` — `field` only selects between
 * `counterpartyName` / `description` for `value_type = 'string'`
 * conditions (RESEARCH Open Question 1, resolved).
 */
final readonly class RuleMatchInput
{
    public function __construct(
        public ?string $counterpartyName,
        public ?string $description,
        public int $settledAmountMinor,
        public CarbonImmutable $postedAt,
    ) {}

    public static function fromCanonical(CanonicalTransaction $tx): self
    {
        return new self(
            counterpartyName: $tx->counterpartyName,
            description: $tx->description,
            settledAmountMinor: $tx->settledAmountMinor,
            postedAt: $tx->postedAt,
        );
    }
}
