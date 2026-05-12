<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

/**
 * The single public surface for any source-statement parser. Each adapter
 * declares a stable format identifier (e.g. 'asn-csv') and parses a local
 * file path lazily into a stream of SourceTransactionDto instances.
 *
 * `parse()` MUST be a Generator (or otherwise lazy) — implementations may
 * not materialize the whole file in memory; ASN exports can easily exceed
 * tens of thousands of rows once multi-year history is in play.
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
}
