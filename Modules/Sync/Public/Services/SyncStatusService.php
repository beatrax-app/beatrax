<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\OpLog\BackfillProgress;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\RefusedOperations;
use Modules\Sync\Internal\Status\PeerSessionTally;
use Modules\Sync\Public\Enums\SyncOverallStatus;

final readonly class SyncStatusService
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private DeferredOpCaptures $deferred,
        private BackfillProgress $backfill,
        private WithheldHistoryReport $withheld,
        private RefusedOperations $refused,
    ) {}

    /**
     * @return array<int, \stdClass>
     */
    public function peerStatuses(int $userId): array
    {
        return $this->db->connection()
            ->table('sync_sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();
    }

    // Drops one recorded session. These rows outlive the device_registry
    // rows they name, so a failed handshake or a removed peer otherwise sat
    // in the list forever and held the overall status on "error" with no
    // way for the user to clear it.
    public function forgetSession(int $userId, string $peerDeviceId): void
    {
        if ($peerDeviceId === '') {
            return;
        }

        $this->db->connection()
            ->table('sync_sessions')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->delete();
    }

    // Drops every session that no confirmed device backs. A session is a
    // record of talking to a peer; once the peer is gone it is history, not
    // a device, and listing it as one is what made removal look impossible.
    public function forgetOrphanedSessions(int $userId): int
    {
        $confirmed = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->pluck('device_id')
            ->all();

        return $this->db->connection()
            ->table('sync_sessions')
            ->where('user_id', $userId)
            ->when($confirmed !== [], fn ($query) => $query->whereNotIn('peer_device_id', $confirmed))
            ->delete();
    }

    // Priority order: a peer needing attention outranks one mid-exchange, which
    // outranks one that cannot be reached, which outranks a finished one.
    // Written as the ladder the caller reads rather than as nested guards.
    public function overallStatus(int $userId): SyncOverallStatus
    {
        $rows = $this->peerStatuses($userId);

        // A hold outranks unknown, and this arm used to answer before anything
        // was asked. Dismissing a peer row drops the session and not the
        // report, so "not enough information yet" got said over a count the
        // device list was still printing two sections further down.
        if ($rows === []) {
            return $this->withheld->isHolding($userId)
                ? SyncOverallStatus::Withheld
                : SyncOverallStatus::Unknown;
        }

        $seen = PeerSessionTally::over($rows, $this->clock->now());

        return match (true) {
            $seen->error => SyncOverallStatus::Error,
            $seen->syncing => SyncOverallStatus::Syncing,
            // Before the finished arm, not after it: a peer that closed an
            // exchange yesterday and cannot be reached today is offline. That
            // arm could not answer offline at all, so it said the opposite.
            $seen->unreachable => SyncOverallStatus::Offline,
            $seen->finished => $this->settledStatus($userId),
            default => SyncOverallStatus::Offline,
        };
    }

    // What is true once every exchange has closed cleanly. "Up to date" is a
    // claim about the whole ledger, not about the last session ending well,
    // and this arm used to read it off the outbound queue alone — so a phone
    // holding 65 refused operations said every device agreed.
    /**
     * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md#the-refusals-the-top-line-could-not-see
     */
    private function settledStatus(int $userId): SyncOverallStatus
    {
        $refused = $this->refused->tally($userId);

        // Ranked on what CLEARS each state, never on whether the reader has an
        // act: nothing clears a terminal refusal, a hold clears on a pass that
        // can answer it, an unsent change clears on the next exchange. Asked
        // only here because error, syncing and offline claim no agreement.
        return match (true) {
            $refused['terminal'] > 0 => SyncOverallStatus::Refused,
            $this->withheld->isHolding($userId) => SyncOverallStatus::Withheld,
            $refused['recoverable'] > 0 => SyncOverallStatus::Held,
            $this->hasUndeliveredLocalOps($userId) => SyncOverallStatus::Behind,
            default => SyncOverallStatus::AllSynced,
        };
    }

    // The count belonging to the status the caller is about to draw, rather
    // than a total: the two halves are different facts about the reader's
    // data, and one number standing for both is the sum of a loss and a wait.
    public function refusedRecordCount(int $userId, SyncOverallStatus $status): int
    {
        $refused = $this->refused->tally($userId);

        return match ($status) {
            SyncOverallStatus::Refused => $refused['terminal'],
            SyncOverallStatus::Held => $refused['recoverable'],
            default => 0,
        };
    }

    // Ops this device authored after the last session ended. There is no
    // per-peer delivery cursor to consult, so the session boundary is the
    // only honest watermark available: nothing written after it can have
    // been sent.
    private function hasUndeliveredLocalOps(int $userId): bool
    {
        // Asked before the watermark, because neither of these has an op yet:
        // a coordinate a keyless process left behind and a row the pre-sync
        // walk has not reached are both owed to a peer and both invisible to
        // op_log_entries, so a device owing them read as up to date.
        if ($this->deferred->hasPending($userId) || $this->backfill->isOpen($userId)) {
            return true;
        }

        $lastSessionEnd = $this->latestInstant(
            $this->db->connection()
                ->table('sync_sessions')
                ->where('user_id', $userId)
                ->pluck('last_seen_at')
                ->all(),
        );

        $selfDeviceId = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', true)
            ->value('device_id');

        // No session to measure from and no identity to measure are the same
        // answer: nothing here can be shown to be owed. Asked together because
        // the second is one indexed lookup, and the memory note below is about
        // op_log_entries, which this does not touch.
        if (! $lastSessionEnd instanceof CarbonImmutable || ! is_string($selfDeviceId) || $selfDeviceId === '') {
            return false;
        }

        // MAX in SQL, not in PHP: op_log_entries has one row per field of every
        // write this device ever made, and plucking them all to parse each into
        // a Carbon exhausted a phone's 128 MB at 200,000 entries — on a screen
        // that mounts this component unconditionally.
        $latestLocalOp = self::instantOf(
            $this->db->connection()
                ->table('op_log_entries')
                ->where('user_id', $userId)
                ->where('device_id', $selfDeviceId)
                ->max('recorded_at'),
        );

        return $latestLocalOp instanceof CarbonImmutable
            && $latestLocalOp->greaterThan($lastSessionEnd);
    }

    // sync_sessions writes ISO8601 with an offset and op_log_entries writes
    // 'Y-m-d H:i:s', so comparing them as strings is not comparing times at
    // all: ' ' sorts before 'T', which made every local op look older than
    // the session it came after. Parse both, compare instants.
    /**
     * @param  array<mixed>  $values
     */
    private function latestInstant(array $values): ?CarbonImmutable
    {
        $latest = null;

        foreach ($values as $value) {
            $parsed = self::instantOf($value);

            if ($parsed !== null && ($latest === null || $parsed->greaterThan($latest))) {
                $latest = $parsed;
            }
        }

        return $latest;
    }

    // A stored stamp read as an instant, or null when it is absent or
    // unparseable — an unreadable timestamp must not decide a status.
    private static function instantOf(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    // Returns null when no session has recorded a last_seen_at. The caller
    // passes the Clock-derived $now so nothing here reaches for the global
    // now() helper.
    /**
     * @param  CarbonImmutable  $now  The reference point for the relative diff.
     */
    public function lastSyncedHuman(CarbonImmutable $now, int $userId): ?string
    {
        $latest = $this->latestLastSeen($userId);

        return $latest === null ? null : self::relativeTime($now, $latest);
    }

    // Shared with SyncStatusSection, which renders the same phrasing for each
    // peer rather than for the newest one.
    public static function relativeTime(CarbonImmutable $now, string $timestamp): ?string
    {
        try {
            $past = CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }

        // Carbon rather than a hand-rolled ladder: SetLocale already gives it
        // the request's language, so this renders in all 26 of them. The
        // ladder it replaces returned English literals, which is why a Dutch
        // phone read "gesynchroniseerd 1h ago".
        return $past->diffForHumans(
            $now,
            syntax: CarbonInterface::DIFF_RELATIVE_TO_NOW,
            short: true,
            options: CarbonInterface::JUST_NOW,
        );
    }

    // Timestamps are compared as strings rather than parsed: they are stored
    // in a fixed-width sortable format, so the largest string is the latest
    // instant and parsing every row to find one maximum would be wasted work.
    private function latestLastSeen(int $userId): ?string
    {
        $latest = null;
        foreach ($this->peerStatuses($userId) as $row) {
            $vars = get_object_vars($row);
            $seen = is_string($vars['last_seen_at'] ?? null) && $vars['last_seen_at'] !== ''
                ? $vars['last_seen_at']
                : null;

            if ($seen !== null && ($latest === null || strcmp($seen, $latest) > 0)) {
                $latest = $seen;
            }
        }

        return $latest;
    }
}
