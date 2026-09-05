<?php

declare(strict_types=1);

namespace Modules\Import\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;

final class PreviewSeedHelper
{
    /**
     * @param  list<UnknownIban>  $accountsToName
     */
    public static function seedRunWithPreview(
        int $userId,
        string $sourceFormat,
        int $newRowCount,
        ?int $accountId = null,
        ?ImportFailureReason $fileFailureReason = null,
        ?string $fileFailureDetail = null,
        array $accountsToName = [],
        int $errorRowCount = 0,
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
                status: PreviewRowStatus::NewRow,
                accountId: $rowAccountId,
                postedAt: '2026-05-10',
                counterpartyName: 'Fixture '.$i,
                counterpartyIban: null,
                description: 'fixture-row-'.$i,
                amountMinor: -1000 - $i,
                currency: 'EUR',
                error: null,
            );
        }

        for ($i = 0; $i < $errorRowCount; $i++) {
            $rows[] = new PreviewRowDto(
                rowIndex: $newRowCount + $i,
                status: PreviewRowStatus::Error,
                accountId: $rowAccountId,
                postedAt: null,
                counterpartyName: null,
                counterpartyIban: null,
                description: null,
                amountMinor: null,
                currency: null,
                error: ImportFailureReason::RowUnreadable->label(),
                errorReason: ImportFailureReason::RowUnreadable,
            );
        }

        /** @var PreviewCache $cache */
        $cache = app(PreviewCache::class);
        $cache->put(
            $run->id,
            new ImportPreviewResult(
                importRunId: $run->id,
                rows: $rows,
                accountsToName: $accountsToName,
                fileFailureReason: $fileFailureReason,
                fileFailureDetail: $fileFailureDetail,
            ),
            canonical: [],
            enrichments: [],
        );

        return $run->id;
    }
}
