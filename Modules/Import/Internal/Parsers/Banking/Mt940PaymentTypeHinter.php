<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Banking;

use Modules\Import\Internal\Parsers\DescriptionKeywordHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ingestion\Public\Enums\SourceFormat;

/**
 * @link ../../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class Mt940PaymentTypeHinter extends DescriptionKeywordHinter
{
    protected const SOURCE_FORMAT = SourceFormat::Mt940->value;

    // Verbatim from AsnCsvPaymentTypeHinter: the MT940 :86: narrative emits
    // the same lexemes as the CSV description.
    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected const KEYWORDS = [
        ['keyword' => 'betaalautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'geldautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'automatische incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'sepa direct debit', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'ideal', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'onlinebetaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'online betaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'sepa credit transfer', 'type' => PaymentType::Transfer, 'confidence' => 70],
        ['keyword' => 'overboeking', 'type' => PaymentType::Transfer, 'confidence' => 70],
    ];
}
