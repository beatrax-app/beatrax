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
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingFetchService
{
    // Enable Banking's live transaction window is documented as ~90-730
    // days; 90 is the conservative end, matching this project's explicit
    // "not a backfill mechanism" boundary for this connector.
    private const INITIAL_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly RemoteSourceAdapter $adapter,
        private readonly RunsImports $importer,
        private readonly ConfirmsImports $confirmer,
        private readonly Clock $clock,
    ) {}

    // Preview-only: parses/normalizes/fingerprints the fetched window but
    // does not commit anything to the ledger.
    public function preview(int $connectionId, User $user): ImportPreviewResult
    {
        [$sourceRows, $idempotencyKey] = $this->buildFetch($connectionId, $user);

        return $this->importer->runFromRemoteFetch($sourceRows, $this->adapter->format(), $user, $idempotencyKey);
    }

    // Preview immediately followed by confirm — the scheduled/auto-sync
    // path, which has no user in the loop to review a preview screen.
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

        // Self-guard on enabled + consent-not-expired, re-loaded from the
        // row, using the same predicate SyncOpenBankingAccountJob applies on
        // pickup — makes this service safe regardless of caller state.
        $enabled = (bool) $connection->enabled;
        $consentExpiresAtRaw = $connection->consent_expires_at ?? null;
        $consentValid = is_string($consentExpiresAtRaw)
            && $consentExpiresAtRaw !== ''
            && CarbonImmutable::parse($consentExpiresAtRaw)->isFuture();

        if (! $enabled || ! $consentValid) {
            throw new RuntimeException(
                "OpenBankingFetchService: connection {$connectionId} is not enabled or its consent has expired — refusing to fetch."
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

        // The secrets file holds exactly one live session at a time. If the
        // user has re-linked a different institution since this row was
        // created, the session would pair one bank's credentials with
        // another bank's account_uid — fail loudly rather than proceed.
        if ($credentials->institutionId !== null && $credentials->institutionId !== $institutionId) {
            throw new RuntimeException(
                "OpenBankingFetchService: connection {$connectionId}'s institution ({$institutionId}) does not "
                ."match the currently active Enable Banking session's institution ({$credentials->institutionId}). "
                .'Only one Enable Banking connection can be live at a time — re-link '.$institutionId
                .' before syncing this connection.'
            );
        }

        $window = $this->resolveWindow($connection);
        $idempotencyKey = self::idempotencyKey($institutionId, $accountUid, $window);

        // Materialize the generator eagerly here rather than handing it raw
        // to the import pipeline, which swallows mid-iteration exceptions
        // into an opaque per-row error status this service's callers need
        // as a real, catchable exception.
        $rows = iterator_to_array($this->adapter->fetch($accountUid, $window, $credentials));

        /** @var Generator<int, SourceTransactionDto> $sourceRows */
        $sourceRows = (static function () use ($rows): Generator {
            yield from $rows;
        })();

        return [$sourceRows, $idempotencyKey];
    }

    // dateFrom resumes from the last successful sync (a never-synced
    // connection falls back to INITIAL_LOOKBACK_DAYS); dateTo is always
    // "now" so a manual sync always wants the freshest available rows.
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
