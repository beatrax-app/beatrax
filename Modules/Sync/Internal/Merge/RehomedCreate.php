<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// A row whose only problem is the id it arrived under. Two devices used apart
// both took the next autoincrement, so the peer's transaction lands on one this
// device already holds; the row itself is not here at all. It is stored under
// an id of this device's own and the peer's id is aliased to it.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class RehomedCreate
{
    public function __construct(
        private DatabaseManager $db,
        private PeerRowAliases $aliases,
        private LoggerInterface $log,
    ) {}

    // The id this device gave the row, or null when it was left to quarantine.
    // Only a payload a natural key can find again is re-homed: without one the
    // next replay of the same create cannot recognise what it wrote, and every
    // replay lands another copy.
    /**
     * @param  array<string, mixed>  $payload  Built, projected, scoped and foreign-key translated by the applier.
     */
    public function under(string $table, array $payload, string $deviceId, int|string $pk, int $userId): ?int
    {
        if (! $this->aliases->naturalKeyIdentifies($table, $payload)) {
            return null;
        }

        unset($payload['id']);

        try {
            $localId = $this->db->connection()->table($table)->insertGetId($payload);
        } catch (Throwable $e) {
            // Null puts the create back on the quarantine path it was taken
            // off, so the reader is told about a row this could not place
            // rather than the refusal going out with the warning below.
            $this->log->warning('RehomedCreate: a row colliding on its id could not be stored under a new one.', [
                'table' => $table,
                'pk' => (string) $pk,
                'device_id' => $deviceId,
                ...SafeExceptionContext::describe($e),
            ]);

            return null;
        }

        $this->aliases->rememberAs($table, $deviceId, $pk, $localId, $payload, $userId);

        return $localId;
    }
}
