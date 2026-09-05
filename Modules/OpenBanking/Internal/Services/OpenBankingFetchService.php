<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingFetchResult;
use Modules\OpenBanking\Internal\Dto\RemoteFetch;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectionException;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

/**
 * @link ../../../../.docs/features/open-banking/fetch-cursor.md
 */
final readonly class OpenBankingFetchService
{
    // Enable Banking documents a ~90-730 day live window; 90 is the conservative
    // end, and this connector is deliberately not a backfill mechanism.
    private const int INITIAL_LOOKBACK_DAYS = 90;

    // How far back of already-read dates every incremental window re-reads. A
    // re-fetched row costs one fingerprint lookup; one backdated further than
    // this overlap is money that never appears.
    /**
     * @link ../../../../.docs/features/open-banking/fetch-cursor.md#why-the-overlap-is-14-days
     */
    private const int BACKDATE_OVERLAP_DAYS = 14;

    public function __construct(
        private DatabaseManager $db,
        private OpenBankingSecretsRepository $secrets,
        private RemoteSourceAdapter $adapter,
        private RunsImports $importer,
        private ConfirmsImports $confirmer,
        private Clock $clock,
    ) {}

    // Stages rows for the reader to confirm and writes none of them, so it
    // never reports a cursor advance: the window has to stay open until
    // something actually commits it.
    public function preview(int $connectionId, User $user): OpenBankingFetchResult
    {
        $fetch = $this->buildFetch($connectionId, $user);

        $preview = $this->importer->runFromRemoteFetch($fetch->rows, $this->adapter->format(), $user, $fetch->idempotencyKey);

        return self::filedNothing($preview)
            ? OpenBankingFetchResult::filedNothing($preview, $fetch->walk)
            : OpenBankingFetchResult::previewed($preview, $fetch->walk);
    }

    // Two runs file nothing and only one of them worked. A window the bank had
    // nothing in did: ConfirmImport refuses a run with no importable row, and
    // asking it to confirm a quiet week would stall the cursor forever. A
    // window whose every row was refused did not, and comes back saying so.
    public function fetchAndConfirm(int $connectionId, User $user): OpenBankingFetchResult
    {
        $fetch = $this->buildFetch($connectionId, $user);

        $preview = $this->importer->runFromRemoteFetch($fetch->rows, $this->adapter->format(), $user, $fetch->idempotencyKey);

        if (self::filedNothing($preview)) {
            return OpenBankingFetchResult::filedNothing($preview, $fetch->walk);
        }

        if ($preview->totalRows() > 0) {
            ($this->confirmer)($preview->importRunId, $user);
        }

        return OpenBankingFetchResult::committed($preview, $fetch->walk, $fetch->window->dateTo);
    }

    // Rows arrived and not one of them can be filed — the arithmetic
    // ConfirmImport derives its own refusal from, read here because that
    // refusal travels as an Import-internal enum this module may not import.
    private static function filedNothing(ImportPreviewResult $preview): bool
    {
        return $preview->totalRows() > 0 && $preview->importableRows() === 0;
    }

    private function buildFetch(int $connectionId, User $user): RemoteFetch
    {
        $connection = $this->db->connection()
            ->table('open_banking_connections')
            ->where('id', $connectionId)
            ->where('user_id', $user->id)
            ->first();

        if ($connection === null) {
            throw OpenBankingConnectionException::notFound($connectionId, $user->id);
        }

        // Re-checked from the row with the same predicate the sync job applies
        // on pickup, so this service is safe regardless of caller state.
        $consent = ConsentWindow::fromStoredRow($connection, $this->clock->now());

        if (! (bool) $connection->enabled || ! $consent->isLive()) {
            throw OpenBankingConnectionException::notFetchable($connectionId);
        }

        $institutionIdRaw = $connection->institution_id ?? null;
        $institutionId = is_string($institutionIdRaw) ? $institutionIdRaw : '';

        $accountUidRaw = $connection->account_uid ?? null;
        $accountUid = is_string($accountUidRaw) && $accountUidRaw !== '' ? $accountUidRaw : null;
        if ($accountUid === null) {
            throw OpenBankingConnectionException::accountNotResolved($connectionId);
        }

        // Addressed by reader AND bank, so a second connection's session can
        // neither be reached from here nor overwrite this one.
        $credentials = $this->secrets->loadOrThrow($user->id, $institutionId);

        $window = $this->resolveWindow($connection);
        $idempotencyKey = self::idempotencyKey($institutionId, $accountUid, $window);

        // Materialized eagerly: the import pipeline swallows mid-iteration
        // exceptions into a per-row error status, and callers here need to catch.
        $walking = $this->adapter->fetch($accountUid, $window, $credentials);
        $rows = iterator_to_array($walking);
        $walk = $walking->getReturn();

        /** @var Generator<int, SourceTransactionDto> $sourceRows */
        $sourceRows = (static function () use ($rows): Generator {
            yield from $rows;
        })();

        return new RemoteFetch($sourceRows, $idempotencyKey, $window, $walk);
    }

    // The cursor is a booking date the rows are committed through, never the
    // wall clock a fetch ran at: a run that wrote nothing leaves it alone, and
    // the overlap is expressed in the bank's dates rather than in elapsed time.
    private function resolveWindow(\stdClass $connection): FetchWindow
    {
        $now = $this->clock->now();

        $cursorRaw = $connection->fetched_through_at ?? null;
        $cursor = is_string($cursorRaw) && $cursorRaw !== ''
            ? CarbonImmutable::parse($cursorRaw)
            : null;

        $dateFrom = $cursor === null
            ? $now->subDays(self::INITIAL_LOOKBACK_DAYS)->startOfDay()
            : $cursor->subDays(self::BACKDATE_OVERLAP_DAYS)->startOfDay();

        return new FetchWindow(dateFrom: $dateFrom, dateTo: $now->startOfDay());
    }

    // Never derived from wall-clock time beyond the window bounds
    // themselves — re-fetching the same window reuses one ImportRun row.
    private static function idempotencyKey(string $institutionId, string $accountUid, FetchWindow $window): string
    {
        return hash('sha256', sprintf(
            'open-banking:%s:%s:%s:%s',
            $institutionId,
            $accountUid,
            $window->dateFrom->toDateString(),
            $window->dateTo->toDateString(),
        ));
    }
}
