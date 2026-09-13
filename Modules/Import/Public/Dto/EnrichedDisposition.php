<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Public\Dto\TransactionBooking;

/**
 * @see FingerprintDisposition
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    /**
     * @param  array<array-key, array{stored: mixed, incoming: mixed}>  $conflictingFields  Per-field disagreements detected during classify() (counterparty_name/description/currency/amount_minor vs. the stored row); ApplyEnrichments resolves each per the user's receipt_conflict_resolution policy. Empty by default (pure source_ref-only enrichment).
     * @param  TransactionBooking|null  $restates  The restating row's own booking terms, set only where the reference lookup matched a row the source filed on another day; ApplyEnrichments adopts them rather than putting them to the reader.
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly ?string $fromSourceRef,
        public readonly string $toSourceRef,
        public readonly array $conflictingFields = [],
        public readonly ?TransactionBooking $restates = null,
    ) {}

    public function status(): PreviewRowStatus
    {
        return PreviewRowStatus::Enriched;
    }

    // What the preview tells the reader this row changes. The source reference
    // is only one of the answers and on a restatement it is never the right
    // one: that arm matches ON the reference, so the two sides are identical by
    // construction and drawing them as a change named the one field that held.
    /**
     * @return array<string, array{from: ?string, to: string}>|null
     */
    public function previewDiff(): ?array
    {
        $diff = $this->fromSourceRef === $this->toSourceRef
            ? []
            : ['source_ref' => ['from' => $this->fromSourceRef, 'to' => $this->toSourceRef]];

        foreach ($this->conflictingFields as $field => $sides) {
            $diff[(string) $field] = [
                'from' => self::asText($sides['stored']),
                'to' => self::asText($sides['incoming']) ?? '',
            ];
        }

        return $diff === [] ? null : $diff;
    }

    // Minor units stay minor units: the preview formats them against the row's
    // own currency, and a string formatted here would freeze the reader's
    // locale into a cached preview replayed at confirm time.
    private static function asText(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => null,
        };
    }
}
