<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Enums\PaymentType;

abstract class DutchNarrativeHinter extends DescriptionKeywordHinter
{
    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected function keywords(): array
    {
        return DutchNarrativeKeywords::scored();
    }
}
