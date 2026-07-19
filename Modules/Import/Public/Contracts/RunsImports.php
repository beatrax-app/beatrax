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

    /**
     * Generator-driven entry point for a remote fetch with no local file to
     * hash — the ONLY legal way a module other than Import (e.g.
     * `Modules\OpenBanking`) can drive the import pipeline, since
     * `ImportPipeline` itself is `Modules\Import\Internal`.
     *
     * `$idempotencyKey` substitutes `runFromUpload()`'s file-SHA256 dedup
     * layer: the caller derives it deterministically from the fetch window
     * (e.g. `hash('sha256', "open-banking:{institutionId}:{accountId}:{dateFrom}:{dateTo}")`),
     * NEVER from wall-clock time, so re-running "Sync now" within the same
     * fetch window reuses one `ImportRun` row instead of creating a fresh
     * one on every click, while a genuinely new fetch window naturally
     * produces a new row. Per-row duplicate detection is still enforced
     * downstream by `FingerprintStage`, independent of this layer.
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     */
    public function runFromRemoteFetch(Generator $sourceRows, string $sourceFormat, User $user, string $idempotencyKey): ImportPreviewResult;
}
