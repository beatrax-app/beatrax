<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Clock;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;

// The receive half of the hybrid logical clock. Without it receive() ran once,
// at OpLogWriter construction, over this device's OWN persisted state, so the
// clock never heard a peer: one an hour ahead stayed ahead forever and every
// later edit made here lost the LWW merge to the value it was replacing.
final readonly class RemoteClockAdvance
{
    public function __construct(private DatabaseManager $db) {}

    // Persisted rather than pushed into a live clock: OpLogWriter is bound
    // transient and restores from hlc_clock_state on every resolve, so the
    // next write this device makes starts from what it has just heard.
    /**
     * @param  list<OpLogEntry>  $accepted  Entries that passed verification.
     * @param  string  $now  The replay's own wall-clock stamp, so the row this
     *                       writes is dated exactly as OpLogWriter dates its own.
     */
    public function absorb(array $accepted, int $userId, string $now): void
    {
        $localDeviceId = $this->localDeviceId($userId);

        if ($localDeviceId === null) {
            return;
        }

        $highest = $this->highestRemote($accepted, $localDeviceId);

        if ($highest === null) {
            return;
        }

        [$msgL, $msgC] = $highest;
        [$lastL, $lastC] = $this->persistedState($userId, $localDeviceId);

        // Already dominated — writing anyway would tick the counter on every
        // rebuild and every re-delivery of history this device has long held.
        if (HybridLogicalClock::compare($msgL, $msgC, '', $lastL, $lastC, '') <= 0) {
            return;
        }

        $clock = new HybridLogicalClock;
        $clock->receive($lastL, $lastC);
        [$newL, $newC] = $clock->receive($msgL, $msgC);

        $this->db->connection()->table('hlc_clock_state')->updateOrInsert(
            ['user_id' => $userId, 'device_id' => $localDeviceId],
            ['last_l' => $newL, 'last_c' => $newC, 'updated_at' => $now],
        );
    }

    // This device's own entries carry no causality to absorb, and a cascade op
    // is re-derived locally on every replay rather than received.
    /**
     * @param  list<OpLogEntry>  $accepted
     * @return array{int, int}|null
     */
    private function highestRemote(array $accepted, string $localDeviceId): ?array
    {
        $maxL = null;
        $maxC = 0;

        foreach ($accepted as $entry) {
            if ($entry->deviceId === $localDeviceId || $entry->deviceId === OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID) {
                continue;
            }

            if ($maxL === null || HybridLogicalClock::compare($entry->hlcL, $entry->hlcC, '', $maxL, $maxC, '') > 0) {
                $maxL = $entry->hlcL;
                $maxC = $entry->hlcC;
            }
        }

        return $maxL === null ? null : [$maxL, $maxC];
    }

    /**
     * @return array{int, int}
     */
    private function persistedState(int $userId, string $localDeviceId): array
    {
        $state = $this->db->connection()
            ->table('hlc_clock_state')
            ->where('user_id', $userId)
            ->where('device_id', $localDeviceId)
            ->first();

        if ($state === null) {
            return [0, 0];
        }

        return [
            is_numeric($state->last_l) ? (int) $state->last_l : 0,
            is_numeric($state->last_c) ? (int) $state->last_c : 0,
        ];
    }

    private function localDeviceId(int $userId): ?string
    {
        $deviceId = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        return is_string($deviceId) && $deviceId !== '' ? $deviceId : null;
    }
}
