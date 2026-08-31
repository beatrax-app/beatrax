<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class PreviewRowDto extends Data
{
    /**
     * @param  array<string, array{from: ?string, to: string}>|null  $diff
     * @param  string|null  $error  Diagnostic text for the log, written only from an exception that declares it names no user data. No view renders it: an exception message is machine text, and the reader gets $errorReason instead.
     * @param  string|null  $errorDetail  What the failure said for itself, past the reason. Written only through the same no-user-data gate as the file-level detail, so unlike $error it is safe to render and to store.
     */
    public function __construct(
        public readonly int $rowIndex,
        public readonly PreviewRowStatus $status,
        public readonly ?int $accountId,
        public readonly ?string $postedAt,
        public readonly ?string $counterpartyName,
        public readonly ?string $counterpartyIban,
        // The last fallback in the Counterparty column, for the bank-fee,
        // interest and ATM rows that carry neither name nor IBAN.
        public readonly ?string $description,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
        public readonly ?array $diff = null,
        public readonly ?PaymentType $paymentType = null,
        public readonly ?string $aliasFriendlyName = null,
        public readonly ?ImportFailureReason $errorReason = null,
        public readonly ?string $errorDetail = null,
    ) {}
}
