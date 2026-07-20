<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Ics;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class IcsPdfPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'ics-pdf';

    // Order is deliberate: `kosten kasopname` precedes the bare
    // `geldmaat` token so the per-withdrawal fee row classifies as Fee
    // rather than Pin even though both lexemes appear nearby on the page.
    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    private const KEYWORDS = [
        ['keyword' => 'kosten kasopname', 'type' => PaymentType::Fee, 'confidence' => 90],
        ['keyword' => 'geldmaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'ideal betaling', 'type' => PaymentType::Online, 'confidence' => 90],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
    ];

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        if ($sourceFormat !== self::SOURCE_FORMAT) {
            return null;
        }

        if ($tx->description === null || $tx->description === '') {
            return null;
        }

        $haystack = mb_strtolower($tx->description);
        foreach (self::KEYWORDS as $entry) {
            if (mb_strpos($haystack, $entry['keyword']) !== false) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: $entry['confidence'],
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }
}
