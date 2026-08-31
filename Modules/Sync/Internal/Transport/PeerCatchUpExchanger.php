<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use Modules\Sync\Internal\OpLog\UnknownOpTypePolicy;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Psr\Log\LoggerInterface;

final readonly class PeerCatchUpExchanger
{
    public const string MSG_CATCH_UP_REQUEST = 'CATCH_UP_REQUEST';

    public const string MSG_CATCH_UP_RESPONSE = 'CATCH_UP_RESPONSE';

    public const string MSG_CATCH_UP_COMPLETE = 'CATCH_UP_COMPLETE';

    public function __construct(
        private DatabaseManager $db,
        private TransportFramer $framer,
        private LoggerInterface $log,
    ) {}

    // How far THIS PEER's stream has been consumed of EACH author, not how far
    // this device's own clock has run: reading hlc_clock_state asked for ops
    // newer than the last LOCAL write, so a peer writing between two of ours
    // was never asked. An omitted $peerDeviceId asks for everything.
    /**
     * @param  string  $localDeviceId  This device's own id, echoed to the peer.
     * @param  string  $peerDeviceId  The peer being asked; '' means "no cursor".
     * @return array{type: string, cursors: list<array{device_id: string, hlc_l: int, hlc_c: int}>, device_id: string, user_id: int}
     */
    public function buildRequest(int $userId, string $localDeviceId, string $peerDeviceId = ''): array
    {
        return [
            'type' => self::MSG_CATCH_UP_REQUEST,
            'cursors' => new PeerCatchUpWatermarks($this->db)->for($userId, $peerDeviceId)->toWire(),
            'device_id' => $localDeviceId,
            'user_id' => $userId,
        ];
    }

    // The cursors a received CATCH_UP_REQUEST is asking from. Both halves of
    // the protocol read it through here, so the clamp cannot drift between them.
    /**
     * @param  array<string, mixed>  $request
     */
    public function cursorsFrom(array $request): PeerCatchUpCursors
    {
        return PeerCatchUpCursors::fromWire($request['cursors'] ?? null);
    }

    /**
     * @param  int  $userId  Scope guard — only ops for this user are returned.
     * @param  PeerCatchUpCursors  $cursors  Per-author watermarks the peer declared.
     * @return CatchUpDelta Countable frame total, iterable as TransportFramer-encoded binary frames.
     */
    public function opsAfterWatermark(int $userId, PeerCatchUpCursors $cursors): CatchUpDelta
    {
        return $this->deltaFor(
            $this->registeredAuthorOps($userId)
                ->where(fn (Builder $q): Builder => $this->aboveEachCursor($q, $cursors)),
        );
    }

    // The same delta measured rather than carried. A progress counter wants a
    // number and a watermark, and building the frames to arrive at them loads
    // the peer's whole history: 50k entries fatalled a phone at its 128 MB
    // ceiling every poll tick, before the cursor it would have advanced.
    /**
     * @return array{count: int, hlc_l: int, hlc_c: int} Watermark echoed back unchanged on an empty delta.
     */
    public function tallyFromAuthorAfter(int $userId, string $authorDeviceId, int $hlcL, int $hlcC): array
    {
        // Skipped rows are not framed, so counting them here would credit the
        // reader with records the wire never carried — UnknownOpTypePolicy::Skip
        // spelled as the SQL that framesFor() applies in PHP.
        $replayable = array_column(OpType::cases(), 'value');

        $count = $this->authorOpsAfter($userId, $authorDeviceId, $hlcL, $hlcC)
            ->whereIn('op_type', $replayable)
            ->count();

        if ($count === 0) {
            return ['count' => 0, 'hlc_l' => $hlcL, 'hlc_c' => $hlcC];
        }

        // Highest in HLC order, which is the last row of the same ordering
        // framesFor() packs by — not MAX(hlc_l) paired with MAX(hlc_c), which
        // could name a pair no entry ever held.
        $top = $this->authorOpsAfter($userId, $authorDeviceId, $hlcL, $hlcC)
            ->whereIn('op_type', $replayable)
            ->orderByDesc('hlc_l')
            ->orderByDesc('hlc_c')
            ->first(['hlc_l', 'hlc_c']);

        $topL = is_object($top) && is_numeric($top->hlc_l ?? null) ? (int) $top->hlc_l : $hlcL;
        $topC = is_object($top) && is_numeric($top->hlc_c ?? null) ? (int) $top->hlc_c : $hlcC;

        return ['count' => $count, 'hlc_l' => $topL, 'hlc_c' => $topC];
    }

    private function authorOpsAfter(int $userId, string $authorDeviceId, int $hlcL, int $hlcC): Builder
    {
        return $this->registeredAuthorOps($userId)
            ->where('device_id', $authorDeviceId)
            ->where(fn (Builder $q): Builder => $this->aheadOf($q, $hlcL, $hlcC));
    }

    // An entry signed by a device the registry has NO row for is verifiable by
    // nobody: one import shipped 12,948 entries and the phone refused 12,476.
    // A REMOVED device still HAS its row, and filtering on confirmed_at
    // withheld its whole history instead.
    private function registeredAuthorOps(int $userId): Builder
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->whereIn('device_id', function (Builder $authors) use ($userId): void {
                $authors->select('device_id')
                    ->from('device_registry')
                    ->where('user_id', $userId);
            });
    }

    // Counted, then streamed. The count pass packs the same batches without
    // encoding them, and pins the highest op-log id it saw so the send pass
    // reads exactly the rows the count was taken over — a row written between
    // the two would otherwise make the declared frame total a lie.
    private function deltaFor(Builder $query): CatchUpDelta
    {
        $counting = $this->packIntoBatches($query, null, reporting: true);

        $frameCount = iterator_count($counting);
        $highestId = $counting->getReturn();

        return new CatchUpDelta($frameCount, function () use ($query, $highestId): \Generator {
            foreach ($this->packIntoBatches($query, $highestId, reporting: false) as $batch) {
                yield $this->framer->encode($batch);
            }
        });
    }

    // An author the peer named no cursor for has never reached it through this
    // stream, so everything that author wrote is still owed — including ops
    // older than anything the peer has already been sent, which is exactly what
    // a third device coming back online after months pushes into a relay.
    private function aboveEachCursor(Builder $query, PeerCatchUpCursors $cursors): Builder
    {
        if ($cursors->isEmpty()) {
            return $query;
        }

        $query->whereNotIn('device_id', array_keys($cursors->byAuthor));

        foreach ($cursors->byAuthor as $author => [$hlcL, $hlcC]) {
            $query->orWhere(function (Builder $q) use ($author, $hlcL, $hlcC): void {
                $q->where('device_id', $author)
                    ->where(fn (Builder $ahead): Builder => $this->aheadOf($ahead, $hlcL, $hlcC));
            });
        }

        return $query;
    }

    private function aheadOf(Builder $query, int $hlcL, int $hlcC): Builder
    {
        return $query->where('hlc_l', '>', $hlcL)
            ->orWhere(function (Builder $tie) use ($hlcL, $hlcC): void {
                $tie->where('hlc_l', '=', $hlcL)->where('hlc_c', '>', $hlcC);
            });
    }

    // Starts a new frame whenever the framer says the next entry would not fit
    // in the current one. The framer is asked rather than second-guessed: it
    // is the class that throws when a batch does not fit, so a prediction made
    // anywhere else is a prediction that can disagree with the throw.
    /**
     * @param  int|null  $upToId  Highest op-log id to read, or null for everything matching.
     * @param  bool  $reporting  Whether an unframable entry is logged — only the count pass reports it.
     * @return \Generator<int, list<OpLogEntry>, void, int> Yields packed batches; returns the highest id read.
     */
    private function packIntoBatches(Builder $query, ?int $upToId, bool $reporting): \Generator
    {
        // Streamed, never fetched: a whole-history delta was held three times
        // over — raw rows, hydrated entries and finished frames — and 50,000
        // entries exhausted a phone's 128 MB ceiling before the exchange that
        // would have advanced the cursor could even start.
        $rows = (clone $query)
            ->when($upToId !== null, fn (Builder $q): Builder => $q->where('id', '<=', $upToId))
            ->orderBy('hlc_l')
            ->orderBy('hlc_c')
            ->orderBy('device_id')
            ->cursor();

        $batch = [];
        $highestId = 0;

        foreach ($rows as $row) {
            $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            $highestId = max($highestId, $id);

            $entry = PersistedOpLogEntries::fromRow($row, UnknownOpTypePolicy::Skip);
            if ($entry === null) {
                continue;
            }

            // An entry no frame can ever hold used to be appended to an empty
            // batch and handed to encode(), whose OverflowException aborted the
            // WHOLE owed delta — for good, since the per-author cursor never
            // advanced and every reconnect rebuilt the same frames.
            if ($this->framer->exceedsFrameBudget($entry)) {
                if ($reporting) {
                    $this->reportUnframable($entry);
                }

                continue;
            }

            if ($batch !== [] && $this->framer->wouldOverflow($batch, $entry)) {
                yield $batch;
                $batch = [];
            }

            $batch[] = $entry;
        }

        if ($batch !== []) {
            yield $batch;
        }

        return $highestId;
    }

    // Error, not warning: an exchange that quietly withheld one row looks like
    // an ordinary clean sync from every surface above it, and the row is real
    // history a peer will now never hold. The value is deliberately absent —
    // it is ledger content, and its LENGTH is the whole of what went wrong.
    /**
     * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#one-entry-that-can-never-be-framed
     */
    private function reportUnframable(OpLogEntry $entry): void
    {
        $this->log->error('PeerCatchUpExchanger: op-log entry is too large to frame and was withheld from the peer.', [
            'user_id' => $entry->userId,
            'table' => $entry->table,
            'pk' => $entry->pk,
            'field' => $entry->field,
            'device_id' => $entry->deviceId,
            'hlc_l' => $entry->hlcL,
            'hlc_c' => $entry->hlcC,
            'entry_bytes' => $this->framer->sizeOf($entry),
            'max_payload_bytes' => TransportFramer::MAX_PAYLOAD_BYTES,
        ]);
    }

    /**
     * @param  CatchUpDelta  $delta  The delta from opsAfterWatermark(), counted but not yet streamed.
     * @return array{type: string, frame_count: int}
     */
    public function buildResponse(CatchUpDelta $delta): array
    {
        return [
            'type' => self::MSG_CATCH_UP_RESPONSE,
            'frame_count' => count($delta),
        ];
    }

    /**
     * @return array{type: string}
     */
    public function buildComplete(): array
    {
        return ['type' => self::MSG_CATCH_UP_COMPLETE];
    }

    /**
     * @param  string  $json  Received control message (plaintext, not a TransportFramer frame).
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException If the message is not a valid catch-up control message.
     */
    public function parseControlMessage(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: invalid JSON control message: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: control message must be a JSON object.'
            );
        }

        /** @var array<string, mixed> $msg */
        $msg = $decoded;

        $type = $msg['type'] ?? null;
        if (! is_string($type)) {
            throw new \UnexpectedValueException(
                'PeerCatchUpExchanger: control message missing "type" string field.'
            );
        }

        return $msg;
    }
}
