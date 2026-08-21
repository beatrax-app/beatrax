<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Spatie\LaravelData\Data;

final class AutoCategorizationOutcomeDto extends Data
{
    public function __construct(
        public readonly CanonicalTransaction $canonical,
        public readonly string $provenance,
        public readonly ?int $ruleId,
        public readonly ?int $memoryId,
    ) {}

    public static function auto(
        CanonicalTransaction $canonical,
        string $provenance,
        ?int $ruleId,
        ?int $memoryId,
    ): self {
        return new self(
            canonical: $canonical,
            provenance: $provenance,
            ruleId: $ruleId,
            memoryId: $memoryId,
        );
    }

    public static function manual(CanonicalTransaction $canonical): self
    {
        return new self(
            canonical: $canonical,
            provenance: 'manual',
            ruleId: null,
            memoryId: null,
        );
    }
}
