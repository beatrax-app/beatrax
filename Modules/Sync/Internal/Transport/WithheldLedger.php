<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// What each peer last said it was holding back, per author it named. Kept apart
// from the introduction it usually accompanies because most of the reasons a
// count arrives with no identity beside it — an author the peer confirms no
// more, a key that failed its own validation — are the ones worth telling.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class WithheldLedger
{
    public function __construct(private DatabaseManager $db) {}

    // The whole of what this peer reported, replacing the whole of what it
    // reported before. An author it no longer names is holding nothing back,
    // and a number left standing after that is a report of a past exchange.
    /**
     * @param  array<array-key, int>  $counts  author device id => entries the peer is holding back.
     */
    public function record(int $userId, string $peerDeviceId, array $counts, string $now): void
    {
        if ($peerDeviceId === '') {
            return;
        }

        $connection = $this->db->connection();
        $authors = array_map(strval(...), array_keys($counts));

        $connection->table('sync_withheld_history')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->when($authors !== [], fn (Builder $q): Builder => $q->whereNotIn('author_device_id', $authors))
            ->delete();

        foreach ($counts as $author => $count) {
            $connection->table('sync_withheld_history')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'peer_device_id' => $peerDeviceId,
                    'author_device_id' => (string) $author,
                ],
                ['entry_count' => max(0, $count), 'updated_at' => $now],
            );
        }
    }

    // The whole of one peer's report, dropped with the peer. Every other row
    // here is replaced by the device that wrote it on its next exchange; one
    // this install has removed never reports again, so its counts are the only
    // ones nothing can revise and the only ones a removal has to take.
    public function forgetPeer(int $userId, string $peerDeviceId): void
    {
        if ($peerDeviceId === '') {
            return;
        }

        $this->db->connection()
            ->table('sync_withheld_history')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->delete();
    }

    /**
     * @return list<array{peer_device_id: string, author_device_id: string, entry_count: int}>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->connection()
            ->table('sync_withheld_history')
            ->where('user_id', $userId)
            ->where('entry_count', '>', 0)
            ->orderBy('author_device_id')
            ->orderBy('peer_device_id')
            ->get();

        $withheld = [];

        foreach ($rows as $row) {
            $peer = $row->peer_device_id;
            $author = $row->author_device_id;
            $count = $row->entry_count;

            if (! is_string($peer) || ! is_string($author) || ! is_numeric($count)) {
                continue;
            }

            $withheld[] = [
                'peer_device_id' => $peer,
                'author_device_id' => $author,
                'entry_count' => (int) $count,
            ];
        }

        return $withheld;
    }
}
