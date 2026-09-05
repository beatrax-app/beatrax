<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\SystemAlert;
use Modules\Sync\Public\Events\EntityMutated;

// The write seam for the system_alerts rows that BELONG to a user, and the only
// place one is put on the op log. A row with a null user_id is about the machine
// that noticed the problem — a corrupt backup is that laptop's — and it is
// raised here too when it may only be raised once, uncaptured either way.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-before-the-write-it-guards
 */
final readonly class SystemAlertWriter
{
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        private DatabaseManager $db,
        private Container $container,
    ) {}

    // Resolved per dispatch, never held: a singleton reaches this class through
    // its constructor, so a dispatcher captured here is captured for that
    // singleton's whole life — and Event::fake() replaces the binding, not an
    // instance already holding one.
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    // Takes the owner as a plain int rather than a nullable one, so the
    // "system-wide rows never travel" rule is a thing the signature enforces
    // instead of a check every caller has to remember.
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function raiseForUser(
        int $userId,
        string $kind,
        string $severity,
        string $message,
        ?array $metadata = null,
    ): SystemAlert {
        return $this->createOwned($userId, $kind, $severity, $message, $metadata, null);
    }

    // One open row per (user, kind), for a fault that stands until somebody acts
    // on it: repeats bury their own first report, and where an unauthenticated
    // caller can provoke the kind, the repeats ARE the attack. The exists() is
    // that policy; the key below is the race two processes can both win.
    /**
     * @param  array<string, mixed>|null  $metadata
     * @return SystemAlert|null null when a row of this kind is already open
     */
    public function raiseOnceForUser(
        int $userId,
        string $kind,
        string $severity,
        string $message,
        ?array $metadata = null,
    ): ?SystemAlert {
        $alreadyOpen = $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->whereNull('acknowledged_at')
            ->exists();

        if ($alreadyOpen) {
            return null;
        }

        try {
            return $this->createOwned(
                $userId,
                $kind,
                $severity,
                $message,
                $metadata,
                self::ownedOpenKey($userId, $kind),
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    // The system-wide counterpart, and the only seam a standing machine-local
    // fault is raised through. Nothing reaches the op log: the row is about the
    // device that noticed. $window buckets the key for the callers whose rule is
    // "at most one an hour" rather than "at most one until somebody acts".
    /**
     * @param  array<string, mixed>|null  $metadata
     * @return SystemAlert|null null when this kind is already open on this machine
     */
    public function raiseOnceSystemWide(
        string $kind,
        string $severity,
        string $message,
        ?array $metadata = null,
        ?int $window = null,
    ): ?SystemAlert {
        try {
            /** @var SystemAlert $alert */
            $alert = SystemAlert::query()->create([
                'user_id' => null,
                'dedup_key' => self::machineOpenKey($kind, $window),
                'kind' => $kind,
                'severity' => $severity,
                'message' => $message,
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $alert;
    }

    // The bucket for a caller whose rule is "at most one an hour", counted off
    // the epoch rather than a wall clock: a DST fall-back repeats a local hour
    // label, and a repeated label would refuse an alert the caller's own
    // recency check had already allowed.
    public static function hourWindow(DateTimeInterface $moment): int
    {
        return intdiv($moment->getTimestamp(), self::SECONDS_PER_HOUR);
    }

    // Prefixed apart so an owned row and a machine-local row of the same kind
    // can never claim one another's key.
    private static function ownedOpenKey(int $userId, string $kind): string
    {
        return 'u'.$userId.':'.$kind;
    }

    private static function machineOpenKey(string $kind, ?int $window): string
    {
        return $window === null ? 'sys:'.$kind : 'sys:'.$kind.'@'.$window;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function createOwned(
        int $userId,
        string $kind,
        string $severity,
        string $message,
        ?array $metadata,
        ?string $dedupKey,
    ): SystemAlert {
        /** @var SystemAlert $alert */
        $alert = SystemAlert::query()->create([
            'user_id' => $userId,
            'dedup_key' => $dedupKey,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $message,
            'metadata' => $metadata,
        ]);

        $this->events()->dispatch(new EntityMutated(
            table: 'system_alerts',
            pk: $alert->id,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: $this->storedRow($alert->id),
        ));

        return $alert;
    }

    // Acknowledging is the one user action on this table, and it is a SET on
    // a row the peer must already hold — so it travels only for rows raised
    // through raiseForUser() above. A system-wide row is silently skipped:
    // the peer raises its own copy from its own probes, under its own id.
    public function captureAcknowledgement(int $alertId, ?int $userId, string $acknowledgedAt): void
    {
        if ($userId === null) {
            return;
        }

        $this->events()->dispatch(new EntityMutated(
            table: 'system_alerts',
            pk: $alertId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: ['acknowledged_at' => $acknowledgedAt],
        ));
    }

    // Read back rather than reused, so `created_at` — a CURRENT_TIMESTAMP default
    // the insert never named — and the JSON metadata text both travel exactly as
    // the database stored them.
    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();

        if ($row === null) {
            return [];
        }

        /** @var array<string, mixed> $fields */
        $fields = (array) $row;

        // `id` rides on the op as its pk. `dedup_key` is this device's claim on
        // an open row and nothing the peer can honour: sent, both devices would
        // hold the same key and whichever raised second would be quarantined.
        unset($fields['id'], $fields['dedup_key']);

        return $fields;
    }
}
