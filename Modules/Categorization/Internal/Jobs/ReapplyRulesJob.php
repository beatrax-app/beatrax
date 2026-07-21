<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Jobs;

use Carbon\CarbonImmutable;
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
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class ReapplyRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK = 500;

    // Long enough to outlive the whole run (including retries) plus a
    // reasonable UI poll gap after completion.
    private const PROGRESS_TTL_SECONDS = 3600;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

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

        $progress = [
            'status' => 'running',
            'checked' => 0,
            'total' => $total,
            'fields_updated' => 0,
            'transactions_updated' => 0,
            'reconciled_skipped' => 0,
            // Rows skipped because match()/applyAtReapply() threw —
            // surfaced so a run that silently skipped rows is still
            // visible in the polled progress payload, not just logged.
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

        $rows->chunk(self::CHUNK)->each(
            /** @param  LazyCollection<int, stdClass>  $chunk */
            function (LazyCollection $chunk) use (
                $engine,
                $applier,
                $statusQuery,
                $user,
                $userId,
                $cache,
                $cacheKey,
                $logger,
                $codec,
                $session,
                $hasKek,
                &$progress,
            ): void {
                $ids = [];
                foreach ($chunk as $row) {
                    if (is_numeric($row->id)) {
                        $ids[] = (int) $row->id;
                    }
                }

                $reconciledIds = $statusQuery->reconciledIdsAmong($userId, $ids);

                foreach ($chunk as $row) {
                    $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
                    if ($transactionId <= 0) {
                        continue;
                    }

                    $progress['checked'] = $progress['checked'] + 1;

                    if (in_array($transactionId, $reconciledIds, true)) {
                        $progress['reconciled_skipped'] = $progress['reconciled_skipped'] + 1;

                        continue;
                    }

                    // Fail-open per row: a malformed condition value would
                    // otherwise throw uncaught out of this chunk closure,
                    // failing the ENTIRE queued job instead of just
                    // skipping the one bad row.
                    try {
                        $input = self::matchInputFromRow($row, $codec, $session, $userId, $hasKek);
                        $matched = $engine->match($input, $user);
                        $changed = $applier->applyAtReapply($matched, $transactionId, $userId);
                    } catch (Throwable $e) {
                        $progress['rows_errored'] = $progress['rows_errored'] + 1;
                        $logger->warning('ReapplyRulesJob skipped a row after a match/apply failure.', [
                            'user_id' => $userId,
                            'transaction_id' => $transactionId,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    if ($changed !== []) {
                        $progress['transactions_updated'] = $progress['transactions_updated'] + 1;
                        $progress['fields_updated'] = $progress['fields_updated'] + count($changed);
                    }
                }

                $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
            }
        );

        $progress['status'] = 'done';
        $progress['finished_at'] = $clock->now()->toIso8601String();
        $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
    }

    private static function matchInputFromRow(
        stdClass $row,
        SensitiveColumnCodec $codec,
        Session $session,
        int $userId,
        bool $hasKek,
    ): RuleMatchInput {
        $counterpartyName = is_string($row->counterparty_name) ? $row->counterparty_name : null;
        $description = is_string($row->description) ? $row->description : null;

        // decryptValue() is a no-op pass-through when encryption isn't
        // enabled, but skipping the call entirely when $hasKek is false
        // avoids a wasted keyring-load attempt per row.
        if ($hasKek) {
            if ($counterpartyName !== null && $counterpartyName !== '') {
                $counterpartyName = $codec->decryptValue('transactions', 'counterparty_name', $counterpartyName, $userId, $session)['value'];
            }
            if ($description !== null && $description !== '') {
                $description = $codec->decryptValue('transactions', 'description', $description, $userId, $session)['value'];
            }
        }

        $settledAmountMinor = is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0;
        $postedAt = is_string($row->posted_at) ? CarbonImmutable::parse($row->posted_at) : CarbonImmutable::now();

        return new RuleMatchInput(
            counterpartyName: $counterpartyName,
            description: $description,
            settledAmountMinor: $settledAmountMinor,
            postedAt: $postedAt,
        );
    }
}
