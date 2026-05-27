<?php

declare(strict_types=1);

namespace Modules\Onboarding\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Ledger\Models\ImportRun;

/**
 * Shared test seeder: creates an ImportRun row with status='previewed' and
 * populates the in-memory PreviewCache with $newRowCount synthetic PreviewRowDto
 * rows so downstream consumers (FirstImportStep, BuildConsolidatedPreviewQuery)
 * see a realistic preview.
 *
 * The optional fourth $accountId argument lets callers pin every seeded row to
 * a specific accounts.id; when omitted, rows fall back to accountId=1 so the
 * three-argument call shape used by the consolidated-preview load test keeps
 * working unchanged.
 */
final class PreviewSeedHelper
{
    public static function seedRunWithPreview(
        int $userId,
        string $sourceFormat,
        int $newRowCount,
        ?int $accountId = null,
    ): int {
        $rowAccountId = $accountId ?? 1;

        /** @var ImportRun $run */
        $run = ImportRun::query()->create([
            'user_id' => $userId,
            'source_format' => $sourceFormat,
            'raw_file_path' => '/tmp/cpl-'.bin2hex(random_bytes(4)).'.bin',
            'sha256' => hash('sha256', 'cpl-'.uniqid('', true)),
            'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
            'status' => 'previewed',
        ]);

        $rows = [];
        for ($i = 0; $i < $newRowCount; $i++) {
            $rows[] = new PreviewRowDto(
                rowIndex: $i,
                status: 'new',
                accountId: $rowAccountId,
                bookedAt: '2026-05-10',
                counterpartyName: 'Fixture '.$i,
                counterpartyIban: null,
                description: 'fixture-row-'.$i,
                categoryName: null,
                amountMinor: -1000 - $i,
                currency: 'EUR',
                error: null,
            );
        }

        /** @var PreviewCache $cache */
        $cache = app(PreviewCache::class);
        $cache->put(
            $run->id,
            new ImportPreviewResult(
                importRunId: $run->id,
                rows: $rows,
                accountsToName: [],
            ),
            canonical: [],
            enrichments: [],
        );

        return $run->id;
    }
}
