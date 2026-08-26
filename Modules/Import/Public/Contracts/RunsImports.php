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
    // $formatHint is required for asn-csv, the one statement format whose
    // dialect its own id does not name; null there raises
    // InvalidArgumentException. Ignored on every other format.
    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint = null): ImportPreviewResult;

    // The single entrypoint the idempotency contract test drives, so a new
    // adapter joins it without re-implementing the wizard's two-step dance.
    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv', ?BankCsvFormatHint $formatHint = null): ImportConfirmResult;

    /**
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     */
    public function runFromRemoteFetch(Generator $sourceRows, string $sourceFormat, User $user, string $idempotencyKey): ImportPreviewResult;
}
