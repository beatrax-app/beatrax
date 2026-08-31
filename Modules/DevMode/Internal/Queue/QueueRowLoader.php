<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * @phpstan-type QueueRow array{
 *   key: string,
 *   queue?: string,
 *   uuid?: string,
 *   name?: string,
 *   attempts?: int,
 *   pendingJobs?: int,
 *   failedJobs?: int,
 *   cancelledAt?: int|null,
 *   finishedAt?: int|null,
 *   createdAt?: int|null,
 *   reservedAt?: int|null,
 *   availableAt?: int|null,
 *   failedAt?: \Carbon\CarbonInterface|null,
 *   payload?: string|null,
 *   options?: string|null,
 * }
 */
final readonly class QueueRowLoader
{
    private const int ROW_LIMIT = 100;

    public function __construct(private DatabaseManager $db) {}

    // The query builder rather than Eloquent: dynamic-call narrowing on
    // Eloquent\Builder trips larastan-strict.
    /**
     * @return list<QueueRow>
     */
    public function load(string $tab): array
    {
        $connection = $this->db->connection();

        return match ($tab) {
            'failed' => $this->mapFailedRows($connection->table('failed_jobs')
                ->orderByDesc('id')
                ->limit(self::ROW_LIMIT)
                ->get()),
            'batches' => $this->mapBatchRows($connection->table('job_batches')
                ->orderByDesc('created_at')
                ->limit(self::ROW_LIMIT)
                ->get()),
            default => $this->mapPendingRows($connection->table('jobs')
                ->orderByDesc('id')
                ->limit(self::ROW_LIMIT)
                ->get()),
        };
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapPendingRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            // Array access, not ->column: larastan-strict flags dynamic
            // property reads on the builder's stdClass rows.
            $vars = get_object_vars($row);
            $key = self::stringKey($vars['id'] ?? null);
            $queue = $vars['queue'] ?? null;
            $attempts = $vars['attempts'] ?? null;
            $createdAt = $vars['created_at'] ?? null;
            $payload = $vars['payload'] ?? null;
            // reserved_at and available_at are what separate a job a worker is
            // running from one that does not come due until next week. Without
            // them all three states rendered as the same row, and the delete
            // button was offered on a job mid-execution.
            $reservedAt = $vars['reserved_at'] ?? null;
            $availableAt = $vars['available_at'] ?? null;
            $out[] = [
                'key' => $key,
                'queue' => is_string($queue) ? $queue : '',
                'attempts' => is_int($attempts) ? $attempts : 0,
                'createdAt' => is_int($createdAt) ? $createdAt : null,
                'reservedAt' => is_int($reservedAt) ? $reservedAt : null,
                'availableAt' => is_int($availableAt) ? $availableAt : null,
                'payload' => is_string($payload) ? $payload : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapFailedRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $vars = get_object_vars($row);
            $uuidRaw = $vars['uuid'] ?? null;
            $uuid = is_string($uuidRaw) ? $uuidRaw : '';
            $failedAtRaw = $vars['failed_at'] ?? null;
            $failedAt = null;
            if (is_string($failedAtRaw)) {
                $failedAt = CarbonImmutable::parse($failedAtRaw);
            } elseif ($failedAtRaw instanceof \DateTimeInterface) {
                $failedAt = CarbonImmutable::instance($failedAtRaw);
            }
            $queue = $vars['queue'] ?? null;
            $payload = $vars['payload'] ?? null;

            $out[] = [
                'key' => $uuid,
                'uuid' => $uuid,
                'queue' => is_string($queue) ? $queue : '',
                'failedAt' => $failedAt,
                'payload' => is_string($payload) ? $payload : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapBatchRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->mapBatchRow(get_object_vars($row));
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $vars
     * @return QueueRow
     */
    private function mapBatchRow(array $vars): array
    {
        $id = $vars['id'] ?? null;
        $name = $vars['name'] ?? null;
        $pendingJobs = $vars['pending_jobs'] ?? null;
        $failedJobs = $vars['failed_jobs'] ?? null;
        $cancelledAt = $vars['cancelled_at'] ?? null;
        $finishedAt = $vars['finished_at'] ?? null;
        $createdAt = $vars['created_at'] ?? null;
        $options = $vars['options'] ?? null;

        return [
            'key' => is_string($id) ? $id : '',
            'name' => is_string($name) ? $name : '',
            'pendingJobs' => is_int($pendingJobs) ? $pendingJobs : 0,
            'failedJobs' => is_int($failedJobs) ? $failedJobs : 0,
            'cancelledAt' => is_int($cancelledAt) ? $cancelledAt : null,
            'finishedAt' => is_int($finishedAt) ? $finishedAt : null,
            'createdAt' => is_int($createdAt) ? $createdAt : null,
            'options' => is_string($options) ? $options : null,
        ];
    }

    // `jobs` keys on an int id, but the Blade selection model works in
    // strings across all three tabs.
    private static function stringKey(mixed $idRaw): string
    {
        if (is_int($idRaw)) {
            return (string) $idRaw;
        }

        return is_string($idRaw) ? $idRaw : '';
    }
}
