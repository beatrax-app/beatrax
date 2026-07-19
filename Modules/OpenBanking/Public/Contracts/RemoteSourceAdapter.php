<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;

/**
 * The single public surface for any remote (aggregator-fetched) source
 * adapter — the remote-source analog of
 * `Modules\Ingestion\Public\Contracts\SourceAdapter`, which parses a local
 * file path. This contract instead fetches directly from the aggregator
 * (Enable Banking), so its second method takes a fetch window and
 * credentials rather than a local path.
 *
 * Deliberately has NO `statementMetadata()` method: unlike a CAMT.053/MT940
 * statement, an Enable Banking `/balances` response is a point-in-time
 * reading, not an opening/closing balance pair for a bounded period — there
 * is no statement-level summary to persist alongside the per-row inserts.
 */
interface RemoteSourceAdapter
{
    /** Stable, lowercase-kebab format identifier (e.g. 'enable-banking'). */
    public function format(): string;

    /**
     * Stream fetched rows for the given institution within the given
     * window, authenticated via the given credentials.
     *
     * @return Generator<int, SourceTransactionDto>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator;
}
