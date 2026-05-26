<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;

/**
 * Single public surface for kicking off an import. The wizard's
 * UploadWizard component invokes `runFromUpload` to produce the preview
 * payload; the cross-module IdempotencyContractTest and the AsnCsvImportTest
 * use the `runAndConfirm` convenience to drive the full preview → confirm
 * cycle in a single call.
 */
interface RunsImports
{
    /**
     * Preview phase: parse + normalize + fingerprint in memory, persist an
     * ImportRun row, cache the canonical batch keyed on the importRunId.
     * No transactions land in the ledger until ConfirmsImports fires.
     *
     * `$formatHint` is required when `$sourceFormat` names a bank-specific
     * CSV dialect (`asn-csv`, `ing-csv`) — CSV is the only ambiguous
     * statement format and the dialect cannot be sniffed reliably, so the
     * caller has to declare it up front. Passing `null` on a CSV source
     * format raises `InvalidArgumentException`; passing a hint with a
     * non-CSV source format is allowed and ignored (the unambiguous
     * formats sniff their own content). Default `null` keeps every
     * existing caller compiling against the contract unchanged.
     */
    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint = null): ImportPreviewResult;

    /**
     * Convenience for tests and CLI: preview then immediately confirm. The
     * idempotency contract exercises this single entrypoint so adapter rows
     * can be added uniformly without re-implementing the wizard's two-step
     * dance.
     *
     * `$formatHint` is required for `asn-csv` / `ing-csv` source formats
     * and ignored for every other format, exactly like the underlying
     * `runFromUpload()` contract.
     */
    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv', ?BankCsvFormatHint $formatHint = null): ImportConfirmResult;
}
