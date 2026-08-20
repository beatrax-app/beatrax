<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\LazyCollection;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Categorization\Internal\Services\RowMatcher;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * @phpstan-type ReapplyProgress array{
 *     status: string,
 *     checked: int,
 *     total: int,
 *     fields_updated: int,
 *     transactions_updated: int,
 *     reconciled_skipped: int,
 *     rows_errored: int,
 *     started_at: string,
 *     finished_at: string|null,
 * }
 *
 * @link ../../../../.docs/features/categorization/reapply-to-history.md
 */
final class ReapplyRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const CHUNK = 500;

    private const PROGRESS_TTL_SECONDS = 3600;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public static function progressCacheKey(int $userId): string
    {
        return "rule-reapply:{$userId}";
    }

    public function handle(
        RuleEngine $engine,
        RuleApplier $applier,
        TransactionStatusQuery $statusQuery,
        DatabaseManager $db,
        Repository $cache,
        Clock $clock,
        LoggerInterface $logger,
        SensitiveColumnCodec $codec,
        Session $session,
        AppLockKeyService $appLockKeyService,
    ): void {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $connection = $db->connection();
        $userId = $this->userId;

        $hasKek = $appLockKeyService->release($session) !== null;
        if (! $hasKek) {
            $logger->warning(
                'ReapplyRulesJob: no app-lock KEK available for this run — counterparty_name/description will be matched using their stored values as-is (any genuinely-encrypted value will fail to substring-match).',
                ['user_id' => $userId],
            );
        }

        $nonSplitQuery = static fn (): Builder => $connection->table('transactions')
            ->where('transactions.user_id', $userId)
            ->whereNotExists(static function (Builder $sub): void {
                $sub->select($sub->raw(1))
                    ->from('transaction_splits')
                    ->whereColumn('transaction_splits.transaction_id', 'transactions.id');
            });

        $total = $nonSplitQuery()->count();

        $cacheKey = self::progressCacheKey($userId);

        /** @var ReapplyProgress $progress */
        $progress = [
            'status' => 'running',
            'checked' => 0,
            'total' => $total,
            'fields_updated' => 0,
            'transactions_updated' => 0,
            'reconciled_skipped' => 0,
            // Rows skipped because match()/applyAtReapply() threw, so a run
            // that skipped rows shows up in the payload, not just the log.
            'rows_errored' => 0,
            'started_at' => $clock->now()->toIso8601String(),
            'finished_at' => null,
        ];
        $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);

        /** @var LazyCollection<int, stdClass> $rows */
        $rows = $nonSplitQuery()
            ->select(['transactions.id', 'counterparty_name', 'description', 'settled_amount_minor', 'posted_at'])
            ->orderBy('transactions.id')
            ->lazyById(self::CHUNK, 'transactions.id', 'id');

        $matcher = new RowMatcher($engine, $applier, $user, $userId, $codec, $session, $hasKek);

        $rows->chunk(self::CHUNK)->each(
            /** @param  LazyCollection<int, stdClass>  $chunk */
            function (LazyCollection $chunk) use (
                $statusQuery,
                $matcher,
                $userId,
                $cache,
                $cacheKey,
                $logger,
                &$progress,
            ): void {
                $reconciledIds = $statusQuery->reconciledIdsAmong($userId, self::numericIds($chunk));

                foreach ($chunk as $row) {
                    self::processRow($row, $matcher, $userId, $reconciledIds, $logger, $progress);
                }

                $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
            }
        );

        $progress['status'] = 'done';
        $progress['finished_at'] = $clock->now()->toIso8601String();
        $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
    }

    /**
     * @param  LazyCollection<int, stdClass>  $chunk
     * @return list<int>
     */
    private static function numericIds(LazyCollection $chunk): array
    {
        $ids = [];
        foreach ($chunk as $row) {
            if (is_numeric($row->id)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $reconciledIds
     * @param  ReapplyProgress  $progress
     */
    private static function processRow(
        stdClass $row,
        RowMatcher $matcher,
        int $userId,
        array $reconciledIds,
        LoggerInterface $logger,
        array &$progress,
    ): void {
        $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
        if ($transactionId <= 0) {
            return;
        }

        $progress['checked'] = $progress['checked'] + 1;

        if (in_array($transactionId, $reconciledIds, true)) {
            $progress['reconciled_skipped'] = $progress['reconciled_skipped'] + 1;

            return;
        }

        // Per-row fail-open: one malformed condition value throws for every
        // row in the walk, so without this the whole queued job dies rather
        // than skipping the rows that rule poisons.
        try {
            $changed = $matcher->changedFields($row, $transactionId);
        } catch (Throwable $e) {
            $progress['rows_errored'] = $progress['rows_errored'] + 1;
            $logger->warning('ReapplyRulesJob skipped a row after a match/apply failure.', [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($changed !== []) {
            $progress['transactions_updated'] = $progress['transactions_updated'] + 1;
            $progress['fields_updated'] = $progress['fields_updated'] + count($changed);
        }
    }
}
