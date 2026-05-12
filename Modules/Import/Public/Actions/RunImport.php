<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Services\EloquentAccountResolver;
use Modules\Ledger\Models\ImportRun;
use RuntimeException;

/**
 * Preview action — the first half of the wizard's two-step ceremony.
 *
 * Flow:
 *  1. Hash the local file (SHA-256). If the user already imported a file
 *     with the same hash AND that import landed (status='confirmed'),
 *     short-circuit with an empty preview — the file-layer idempotency
 *     guard backed by the UNIQUE `(user_id, sha256)` index on import_runs.
 *  2. Otherwise create (or reuse) an ImportRun row with status='previewed'
 *     so the wizard has a stable id to reference.
 *  3. Run the ImportPipeline (parse → normalize → fingerprint) and cache
 *     the canonical batch under that import run id for the Confirm step.
 *
 * `runAndConfirm` is the test/CLI convenience that drives both halves in
 * one call — the cross-module IdempotencyContractTest depends on it.
 */
final class RunImport implements RunsImports
{
    public function __construct(
        private readonly ImportPipeline $pipeline,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
        private readonly ConfirmImport $confirmAction,
    ) {}

    public function runFromUpload(string $localPath, string $sourceFormat, User $user, string $originalFilename): ImportPreviewResult
    {
        $sha = hash_file('sha256', $localPath);
        if (! is_string($sha)) {
            throw new RuntimeException('Could not compute SHA-256 of uploaded file.');
        }

        /** @var ImportRun|null $existing */
        $existing = ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha)
            ->first();

        $importRun = $existing ?? ImportRun::create([
            'user_id' => $user->id,
            'source_format' => $sourceFormat,
            'raw_file_path' => $localPath,
            'sha256' => $sha,
            'uploaded_at' => $this->clock->now(),
            'status' => 'previewed',
        ]);

        $accounts = new EloquentAccountResolver($user);
        $result = $this->pipeline->preview($localPath, $sourceFormat, $accounts, $user, $importRun->id);

        $previewResult = new ImportPreviewResult(
            importRunId: $importRun->id,
            rows: $result['rows'],
            accountsToName: $result['unknownIbans'],
        );

        $this->cache->put($importRun->id, $previewResult, $result['canonical']);

        return $previewResult;
    }

    public function runAndConfirm(string $localPath, string $sourceFormat, User $user, string $originalFilename = 'fixture.csv'): ImportConfirmResult
    {
        $preview = $this->runFromUpload($localPath, $sourceFormat, $user, $originalFilename);

        return ($this->confirmAction)($preview->importRunId, $user);
    }
}
