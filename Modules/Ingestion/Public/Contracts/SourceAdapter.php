<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\StatementSummaryData;

/**
 * The single public surface for any source-statement parser. Each adapter
 * declares a stable format identifier (e.g. 'asn-csv') and parses a local
 * file path lazily into a stream of SourceTransactionDto instances.
 *
 * `parse()` MUST be a Generator (or otherwise lazy) — implementations may
 * not materialize the whole file in memory; ASN exports can easily exceed
 * tens of thousands of rows once multi-year history is in play.
 *
 * After `parse()` has been iterated to exhaustion, `statementMetadata()`
 * returns any statement-level facts the format carries (opening balance,
 * closing balance, period dates, entry count). CSV adapters return `null`;
 * CAMT.053 / MT940 adapters return a populated DTO so the ImportPipeline
 * can persist a statement_summaries row alongside the per-row inserts.
 */
interface SourceAdapter
{
    /** Stable, lowercase-kebab format identifier. */
    public function format(): string;

    /**
     * Stream parsed rows from the given local file path. The AccountResolver
     * is consulted per IBAN so the wizard can branch on Unknown variants
     * before the adapter yields a DTO.
     *
     * @return Generator<int, SourceTransactionDto>
     */
    public function parse(string $localPath, AccountResolver $accounts): Generator;

    /**
     * Statement-level metadata captured during the most recent parse() run.
     * Returns NULL when the source format carries no statement metadata
     * (CSV) or when parse() has not been invoked yet. Adapters that return
     * non-null populate `importRunId` and `accountId` with placeholder
     * zeros — the pipeline overrides both via `withImportRunId()` and
     * `withAccountId()` before invoking the writer.
     */
    public function statementMetadata(): ?StatementSummaryData;
}
