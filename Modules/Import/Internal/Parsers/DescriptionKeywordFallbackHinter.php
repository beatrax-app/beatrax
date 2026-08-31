<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Enums\PaymentType;

final class DescriptionKeywordFallbackHinter extends DescriptionKeywordHinter
{
    private const int CONFIDENCE = 40;

    protected function handles(string $sourceFormat): bool
    {
        unset($sourceFormat);

        return true;
    }

    // One confidence for every keyword, stamped on at read time so there is
    // one number to change rather than one per row.
    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected function keywords(): array
    {
        return DutchNarrativeKeywords::atConfidence(self::CONFIDENCE);
    }
}
