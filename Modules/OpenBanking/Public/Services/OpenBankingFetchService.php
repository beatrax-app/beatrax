<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use RuntimeException;

/**
 * Fetch orchestration: loads a connection's credentials + resolved
 * account uid, builds the deterministic window idempotency key
 * (Pitfall 1, matching `RunsImports::runFromRemoteFetch()`'s documented
 * composition), drives `RemoteSourceAdapter::fetch()` to obtain the
 * generator, and lands it through the SAME `RunsImports::
 * runFromRemoteFetch()` entry point file uploads use — no OpenBanking-only
 * fork of the import pipeline exists.
 *
 * Two call shapes for the two callers this module has:
 *  - `preview()` — a manual "Sync now" surface returns the preview for
 *    the wizard's existing consolidated-preview UI to route, exactly
 *    like an upload does. No ledger write happens until a later
 *    `ConfirmsImports` call.
 *  - `fetchAndConfirm()` — the scheduled daily job (`SyncOpenBankingAccountJob`)
 *    commits automatically via `ConfirmsImports`, since there is no user
 *    in the loop to review a background fetch.
 *
 * `account_uid` (19-09 carried gap from 19-08: `EnableBankingSourceAdapter::
 * fetch()`'s first parameter IS the Enable Banking account uid, but
 * nothing persisted `createSession()`'s `accounts[].uid` before this
 * plan) is read back from the `open_banking_connections` row
 * `OpenBankingCallbackController` now populates. A connection with no
 * resolved account_uid yet cannot be fetched — `buildFetch()` throws a
 * clear `RuntimeException` rather than passing an empty string through
 * to the adapter/HTTP client.
 */
final class OpenBankingFetchService
{
    /**
     * First-ever sync lookback window when `last_successful_sync_at` is
     * still null. Enable Banking's live transaction window is
     * documented as ~90-730 days (RESEARCH.md) — 90 is the conservative
     * end, matching the SPEC's explicit "OB is not a backfill
     * mechanism" boundary (full-history OB backfill is out of scope).
     */
    private const INITIAL_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly RemoteSourceAdapter $adapter,
        private readonly RunsImports $importer,
        private readonly ConfirmsImports $confirmer,
        private readonly Clock $clock,
    ) {}

    /**
     * Preview-only: parses/normalizes/fingerprints the fetched window but
     * does not commit anything to the ledger.
     */
    public function preview(int $connectionId, User $user): ImportPreviewResult
    {
        [$sourceRows, $idempotencyKey] = $this->buildFetch($connectionId, $user);

        return $this->importer->runFromRemoteFetch($sourceRows, $this->adapter->format(), $user, $idempotencyKey);
    }

    /**
     * Preview immediately followed by confirm — the scheduled/auto-sync
     * path, which has no user in the loop to review a preview screen.
     */
    public function fetchAndConfirm(int $connectionId, User $user): ImportConfirmResult
    {
        $preview = $this->preview($connectionId, $user);

        return ($this->confirmer)($preview->importRunId, $user);
    }

    /**
     * @return array{0: Generator<int, SourceTransactionDto>, 1: string}
     */
    private function buildFetch(int $connectionId, User $user): array
    {
        $connection = $this->db->connection()
            ->table('open_banking_connections')
            ->where('id', $connectionId)
            ->where('user_id', $user->id)
            ->first();

        if ($connection === null) {
            throw new RuntimeException(
                "OpenBankingFetchService: no open_banking_connections row {$connectionId} for user {$user->id}."
            );
        }

        $institutionIdRaw = $connection->institution_id ?? null;
        $institutionId = is_string($institutionIdRaw) ? $institutionIdRaw : '';

        $accountUidRaw = $connection->account_uid ?? null;
        $accountUid = is_string($accountUidRaw) && $accountUidRaw !== '' ? $accountUidRaw : null;
        if ($accountUid === null) {
            throw new RuntimeException(
                "OpenBankingFetchService: connection {$connectionId} has no resolved account_uid yet — "
                .'the consent dance must capture accounts[].uid before a fetch can run.'
            );
        }

        $credentials = $this->secrets->load();
        if ($credentials === null) {
            throw new RuntimeException(
                'OpenBankingFetchService: no Enable Banking application credentials are persisted.'
            );
        }

        $window = $this->resolveWindow($connection);
        $idempotencyKey = self::idempotencyKey($institutionId, $accountUid, $window);

        // Materialize the adapter's generator EAGERLY, right here, rather
        // than handing the raw lazy generator straight to
        // `RunsImports::runFromRemoteFetch()`. `ImportPipeline::
        // buildPreviewRows()` wraps its whole per-row loop in a
        // try/catch(Throwable) that converts ANY exception raised
        // mid-iteration (a network failure, an HTTP 401/403 consent
        // failure, a malformed aggregator response) into a single opaque
        // `PreviewRowDto(status: 'error')` — by design, so a bad upload
        // still renders a preview screen instead of a 500. That swallow
        // is correct for the wizard's upload path but would silently hide
        // every fetch failure from `SyncOpenBankingAccountJob`, which
        // needs a real, catchable exception to drive its two-timestamp
        // accounting (`last_successful_sync_at` must never advance on
        // failure) and its consent-failure -> `OpenBankingConsentFailed`
        // detection. Iterating here means any adapter/HTTP-level failure
        // propagates out of THIS method, before the pipeline ever gets a
        // chance to swallow it.
        $rows = iterator_to_array($this->adapter->fetch($accountUid, $window, $credentials));

        /** @var Generator<int, SourceTransactionDto> $sourceRows */
        $sourceRows = (static function () use ($rows): Generator {
            yield from $rows;
        })();

        return [$sourceRows, $idempotencyKey];
    }

    /**
     * `dateFrom` resumes from the last successful sync (so a healthy
     * daily cadence only ever asks for the new day's rows); a
     * never-synced connection falls back to `INITIAL_LOOKBACK_DAYS`.
     * `dateTo` is always "now" — a manual "Sync now" click always wants
     * the freshest available rows, never a stale cached window.
     */
    private function resolveWindow(\stdClass $connection): FetchWindow
    {
        $now = $this->clock->now();

        $lastSuccessRaw = $connection->last_successful_sync_at ?? null;
        $lastSuccess = is_string($lastSuccessRaw) && $lastSuccessRaw !== ''
            ? CarbonImmutable::parse($lastSuccessRaw)
            : null;

        $dateFrom = $lastSuccess?->startOfDay() ?? $now->subDays(self::INITIAL_LOOKBACK_DAYS)->startOfDay();

        return new FetchWindow(dateFrom: $dateFrom, dateTo: $now->startOfDay());
    }

    /**
     * Matches `RunsImports::runFromRemoteFetch()`'s documented key
     * composition verbatim: `hash('sha256', "open-banking:{institutionId}:{accountId}:{dateFrom}:{dateTo}")`.
     * Never derived from wall-clock time beyond the window bounds
     * themselves — re-fetching the SAME window (e.g. a same-day retried
     * "Sync now") reuses one `ImportRun` row.
     */
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
