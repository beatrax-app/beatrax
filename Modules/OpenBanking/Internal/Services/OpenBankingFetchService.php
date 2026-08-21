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
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectionException;

final class OpenBankingFetchService
{
    // Enable Banking documents a ~90-730 day live window; 90 is the conservative
    // end, and this connector is deliberately not a backfill mechanism.
    private const INITIAL_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly RemoteSourceAdapter $adapter,
        private readonly RunsImports $importer,
        private readonly ConfirmsImports $confirmer,
        private readonly Clock $clock,
    ) {}

    public function preview(int $connectionId, User $user): ImportPreviewResult
    {
        [$sourceRows, $idempotencyKey] = $this->buildFetch($connectionId, $user);

        return $this->importer->runFromRemoteFetch($sourceRows, $this->adapter->format(), $user, $idempotencyKey);
    }

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
            throw OpenBankingConnectionException::notFound($connectionId, $user->id);
        }

        // Re-checked from the row with the same predicate the sync job applies
        // on pickup, so this service is safe regardless of caller state.
        $enabled = (bool) $connection->enabled;
        $consentExpiresAtRaw = $connection->consent_expires_at ?? null;
        $consentValid = is_string($consentExpiresAtRaw)
            && $consentExpiresAtRaw !== ''
            && CarbonImmutable::parse($consentExpiresAtRaw)->isFuture();

        if (! $enabled || ! $consentValid) {
            throw OpenBankingConnectionException::notFetchable($connectionId);
        }

        $institutionIdRaw = $connection->institution_id ?? null;
        $institutionId = is_string($institutionIdRaw) ? $institutionIdRaw : '';

        $accountUidRaw = $connection->account_uid ?? null;
        $accountUid = is_string($accountUidRaw) && $accountUidRaw !== '' ? $accountUidRaw : null;
        if ($accountUid === null) {
            throw OpenBankingConnectionException::accountNotResolved($connectionId);
        }

        $credentials = $this->secrets->loadOrThrow();

        // The secrets file holds one live session, so a re-link since this row
        // was created would pair one bank's credentials with another's uid.
        if ($credentials->institutionId !== null && $credentials->institutionId !== $institutionId) {
            throw OpenBankingConnectionException::institutionMismatch(
                $connectionId,
                $institutionId,
                $credentials->institutionId,
            );
        }

        $window = $this->resolveWindow($connection);
        $idempotencyKey = self::idempotencyKey($institutionId, $accountUid, $window);

        // Materialized eagerly: the import pipeline swallows mid-iteration
        // exceptions into a per-row error status, and callers here need to catch.
        $rows = iterator_to_array($this->adapter->fetch($accountUid, $window, $credentials));

        /** @var Generator<int, SourceTransactionDto> $sourceRows */
        $sourceRows = (static function () use ($rows): Generator {
            yield from $rows;
        })();

        return [$sourceRows, $idempotencyKey];
    }

    // dateFrom resumes from the last successful sync, falling back to
    // INITIAL_LOOKBACK_DAYS; dateTo is always now.
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
