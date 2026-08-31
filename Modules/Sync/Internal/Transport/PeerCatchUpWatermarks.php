<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\OpLog\OpLogEntry;

// One cursor per (peer, AUTHOR), mirroring what InitialSyncPuller keeps in
// mobile_sync_progress.last_hlc_l. The request used hlc_clock_state — this
// device's last LOCAL write, no statement about the peer — so everything the
// peer wrote before this device's last edit fell below it and was never asked for.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#catch-up-an-hlc-watermark-exchange
 */
final readonly class PeerCatchUpWatermarks
{
    public function __construct(private DatabaseManager $db) {}

    // Empty — "send me everything" — for a peer never heard from, and for the
    // empty peer id a caller that cannot name its peer passes: over-asking is
    // recoverable, silently skipping an author's history is not.
    public function for(int $userId, string $peerDeviceId): PeerCatchUpCursors
    {
        if ($peerDeviceId === '') {
            return PeerCatchUpCursors::none();
        }

        $rows = $this->db->connection()
            ->table('sync_peer_catch_up_state')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->get();

        $byAuthor = [];

        foreach ($rows as $row) {
            $author = $row->author_device_id ?? null;

            if (! is_string($author) || $author === '') {
                continue;
            }

            $lastL = $row->last_l ?? null;
            $lastC = $row->last_c ?? null;

            $byAuthor[$author] = [
                is_numeric($lastL) ? (int) $lastL : 0,
                is_numeric($lastC) ? (int) $lastC : 0,
            ];
        }

        return PeerCatchUpCursors::of($byAuthor);
    }

    // Advanced from what the peer actually delivered, never from local time,
    // and only ever forwards PER AUTHOR: an op of one author arriving late must
    // not pull that author's cursor back, and must not touch anyone else's.
    /**
     * @param  list<OpLogEntry>  $delivered  Entries this peer just sent, authored by any device.
     */
    public function advance(int $userId, string $peerDeviceId, array $delivered, string $now): void
    {
        if ($peerDeviceId === '' || $delivered === []) {
            return;
        }

        $held = $this->for($userId, $peerDeviceId);
        $advanced = [];

        foreach ($delivered as $entry) {
            [$maxL, $maxC] = $advanced[$entry->deviceId] ?? $held->for($entry->deviceId);

            if (HybridLogicalClock::compare($entry->hlcL, $entry->hlcC, '', $maxL, $maxC, '') > 0) {
                $advanced[$entry->deviceId] = [$entry->hlcL, $entry->hlcC];
            }
        }

        foreach ($advanced as $author => [$lastL, $lastC]) {
            $this->db->connection()->table('sync_peer_catch_up_state')->updateOrInsert(
                ['user_id' => $userId, 'peer_device_id' => $peerDeviceId, 'author_device_id' => $author],
                ['last_l' => $lastL, 'last_c' => $lastC, 'updated_at' => $now],
            );
        }
    }
}
