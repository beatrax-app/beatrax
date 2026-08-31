<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Ics;

use Modules\Import\Internal\Parsers\DescriptionKeywordHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ingestion\Public\Enums\SourceFormat;

final class IcsPdfPaymentTypeHinter extends DescriptionKeywordHinter
{
    protected const SOURCE_FORMAT = SourceFormat::IcsPdf->value;

    // `kosten kasopname` precedes the bare `geldmaat` so the per-withdrawal
    // fee row classifies as Fee, not Pin; both lexemes sit near each other
    // on the page.
    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected const KEYWORDS = [
        ['keyword' => 'kosten kasopname', 'type' => PaymentType::Fee, 'confidence' => 90],
        ['keyword' => 'geldmaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'ideal betaling', 'type' => PaymentType::Online, 'confidence' => 90],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
    ];
}
