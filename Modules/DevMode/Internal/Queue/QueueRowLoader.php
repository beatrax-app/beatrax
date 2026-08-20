<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

// Reads and normalises the per-tab queue row set for QueueInspectorPage. The
// raw query builder returns stdClass rows; this collaborator maps each tab's
// columns into the single predictable QueueRow array shape the Blade view
// consumes, keeping larastan-strict happy off the Livewire component.
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
 *   failedAt?: \Carbon\CarbonInterface|null,
 *   payload?: string|null,
 *   options?: string|null,
 * }
 */
final readonly class QueueRowLoader
{
    private const ROW_LIMIT = 100;

    public function __construct(private DatabaseManager $db) {}

    // Raw query builder + an explicit array mapper keeps larastan-strict
    // happy (no Eloquent dynamic-call narrowing) and gives the Blade a
    // single, predictable contract.
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
            // get_object_vars() converts the stdClass row to an array so
            // larastan-strict-rules does not flag the dynamic property
            // access on the raw query builder's per-column properties.
            $vars = get_object_vars($row);
            $key = self::stringKey($vars['id'] ?? null);
            $queue = $vars['queue'] ?? null;
            $attempts = $vars['attempts'] ?? null;
            $createdAt = $vars['created_at'] ?? null;
            $payload = $vars['payload'] ?? null;
            $out[] = [
                'key' => $key,
                'queue' => is_string($queue) ? $queue : '',
                'attempts' => is_int($attempts) ? $attempts : 0,
                'createdAt' => is_int($createdAt) ? $createdAt : null,
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

    // The pending `jobs` table keys on an int id; failed jobs and batches
    // key on a string uuid. Coerces either to the string row key the
    // Blade selection model expects, defaulting to '' for absent ids.
    private static function stringKey(mixed $idRaw): string
    {
        if (is_int($idRaw)) {
            return (string) $idRaw;
        }

        return is_string($idRaw) ? $idRaw : '';
    }
}
