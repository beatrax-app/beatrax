<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;

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
     */
    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename): ImportPreviewResult;

    /**
     * Convenience for tests and CLI: preview then immediately confirm. The
     * idempotency contract exercises this single entrypoint so adapter rows
     * can be added uniformly without re-implementing the wizard's two-step
     * dance.
     */
    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv'): ImportConfirmResult;
}
