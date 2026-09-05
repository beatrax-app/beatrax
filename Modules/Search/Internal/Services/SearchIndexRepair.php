<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Psr\Log\LoggerInterface;

// Drains what SearchIndexWriter refused to write. Nothing here decides whether
// a column is readable: it calls the same writer the refusal came from, so a
// row still unreadable is re-queued by that writer rather than judged twice.
/**
 * @link ../../../../.docs/features/search/architecture.md#a-column-this-process-cannot-read
 */
final readonly class SearchIndexRepair implements SearchIndexRepairContract
{
    public function __construct(
        private SearchIndexRepairQueue $queue,
        private SearchIndexWriterContract $writer,
        private LoggerInterface $log,
    ) {}

    public function hasWork(int $userId, ?string $keyringFingerprint): bool
    {
        return $this->queue->hasWork($userId, $keyringFingerprint);
    }

    public function repair(int $userId, ?string $keyringFingerprint): int
    {
        $claimed = $this->queue->claim($userId, $keyringFingerprint, SearchIndexRepairQueue::DRAIN_LIMIT);

        foreach ($claimed as $transactionId) {
            $this->writer->upsertForTransaction($transactionId, $userId);
        }

        // Counted by what is no longer owed rather than by what was attempted:
        // a row this pass still could not read is left standing, and reporting
        // it as repaired would claim the words are findable again.
        $repaired = count($claimed) - $this->queue->markAnswered($userId, $claimed, $keyringFingerprint);

        if ($repaired > 0) {
            $this->log->info(
                'SearchIndexRepair: rebuilt search bodies a keyless process could not write.',
                ['userId' => $userId, 'transactions' => $repaired],
            );
        }

        return $repaired;
    }
}
