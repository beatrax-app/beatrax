<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\DeferredOpKind;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Throwable;

// A data migration that deletes a replicated row has to say so, or the next
// rebuild replays the row's create over the hole and hands it straight back —
// `OpLogRebuilder::verifyRestored()` counts what went missing and has no word
// for what came back.

// `OpLogWriter` is the seam that would say it, and a migration cannot reach
// it: `OpLogWriterFactory::forCurrentUser()` needs a device identity, and
// `DeviceIdentityLoader::read()` opens a sealed key-file with the app-lock
// KEK, which lives in a session no console process has.

// So the entry is written here, in the one shape admissible without a key.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
final class KeylessTombstone
{
    // Announce BEFORE the rows go, because the owner is read off the row: an
    // `op_log_entries.user_id` is NOT NULL, and the alert's is nullable.
    /**
     * @param  list<int>  $ids
     */
    public static function announce(Connection $connection, string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $now = self::clock()?->now();
        $recordedAt = $now?->toDateTimeString() ?? gmdate('Y-m-d H:i:s');
        $nowMs = $now?->getTimestampMs() ?? (int) (microtime(true) * 1000);

        foreach ($connection->table($table)->whereIn('id', $ids)->whereNotNull('user_id')
            ->orderBy('id')->select(['id', 'user_id'])->cursor() as $row) {
            if (! is_numeric($row->id) || ! is_numeric($row->user_id)) {
                continue;
            }

            self::persist($connection, $table, (string) $row->id, (int) $row->user_id, $nowMs, $recordedAt);
            self::owe($table, (string) $row->id, (int) $row->user_id);
        }
    }

    // Authored by `system-cascade` rather than by this device, because the
    // Ed25519 gate is the whole admission rule and only that author bypasses
    // it (`OpLogEntryVerifier::isSystemDevice()`). The same row under this
    // device's own id, signed with nothing, quarantines as a forgery.
    private static function persist(
        Connection $connection,
        string $table,
        string $pk,
        int $userId,
        int $nowMs,
        string $recordedAt,
    ): void {
        [$hlcL, $hlcC] = self::stampAfterEveryOpOn($connection, $table, $pk, $userId, $nowMs);

        // Keyed without the HLC, unlike the verifier's own upsert: a re-run of
        // an append-only migration must leave one tombstone per row rather than
        // one per run, and refreshing its stamp is what keeps the newest verdict
        // the winning one.
        $connection->table('op_log_entries')->updateOrInsert(
            [
                'user_id' => $userId,
                'device_id' => OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID,
                'table_name' => $table,
                'pk' => $pk,
                'field' => OpLogWriter::TOMBSTONE_FIELD,
            ],
            [
                'op_type' => OpType::DeleteTombstone->value,
                'value' => null,
                'gdk_epoch' => null,
                'hlc_l' => $hlcL,
                'hlc_c' => $hlcC,
                'signature' => '',
                'recorded_at' => $recordedAt,
            ],
        );
    }

    // `HybridLogicalClock::receive()` against the row's own highest op, on a
    // clock that has seen nothing else. A tombstone that does not outrank the
    // create it answers loses the delete-wins comparison and the row comes
    // back — which is the same trap `TransferPairCascade` documents.
    /**
     * @return array{int, int}
     */
    private static function stampAfterEveryOpOn(
        Connection $connection,
        string $table,
        string $pk,
        int $userId,
        int $nowMs,
    ): array {
        $top = $connection->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->orderByDesc('hlc_l')
            ->orderByDesc('hlc_c')
            ->first(['hlc_l', 'hlc_c']);

        $maxL = is_object($top) && is_numeric($top->hlc_l ?? null) ? (int) $top->hlc_l : 0;
        $maxC = is_object($top) && is_numeric($top->hlc_c ?? null) ? (int) $top->hlc_c : 0;

        return $nowMs > $maxL ? [$nowMs, 0] : [$maxL, $maxC + 1];
    }

    // The system author is local by construction — a peer carries ops only for
    // an author it holds a key for (`IntroductionOffers::carriedAuthorsFor()`),
    // and `system-cascade` is no device. So the delete is owed to the peer as a
    // coordinate too, and the first unlocked request signs and sends it.
    private static function owe(string $table, string $pk, int $userId): void
    {
        try {
            Container::getInstance()->make(DeferredOpCaptures::class)->record(
                $userId,
                $table,
                $pk,
                OpLogWriter::TOMBSTONE_FIELD,
                DeferredOpKind::Delete,
            );
        } catch (Throwable) {
            // A migration older than the queue's own table runs before it
            // exists. The entry above is already written, so the local rebuild
            // is answered either way and only the peer's copy waits.
        }
    }

    // Resolved rather than injected, for the reason `ModuleMigration` resolves
    // its database manager: a migration runs outside the request lifecycle and
    // has no constructor anyone can reach.
    private static function clock(): ?Clock
    {
        try {
            return Container::getInstance()->make(Clock::class);
        } catch (Throwable) {
            return null;
        }
    }
}
