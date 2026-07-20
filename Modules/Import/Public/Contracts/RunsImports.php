<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Generator;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

/**
 * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
 */
interface RunsImports
{
    // `$formatHint` is required for a bank-specific CSV dialect
    // (`asn-csv`, `ing-csv`) — CSV is the only ambiguous statement
    // format and can't be sniffed reliably. Null on a CSV format raises
    // InvalidArgumentException; ignored (not required) on other formats.
    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint = null): ImportPreviewResult;

    // Preview then immediately confirm — the single entrypoint the
    // idempotency contract test exercises so adapter rows can be added
    // uniformly without re-implementing the wizard's two-step dance.
    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv', ?BankCsvFormatHint $formatHint = null): ImportConfirmResult;

    /**
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     */
    public function runFromRemoteFetch(Generator $sourceRows, string $sourceFormat, User $user, string $idempotencyKey): ImportPreviewResult;
}
