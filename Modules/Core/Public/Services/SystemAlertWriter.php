<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Sync\Public\Events\EntityMutated;

// The write seam for the system_alerts rows that BELONG to a user, and the
// only place one is put on the op log. A row with a null user_id is about the
// machine that noticed the problem — a corrupt backup is that laptop's — and
// the probes that raise those keep writing the model directly, uncaptured.
final readonly class SystemAlertWriter
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

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
        /** @var SystemAlert $alert */
        $alert = SystemAlert::query()->create([
            'user_id' => $userId,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $message,
            'metadata' => $metadata,
        ]);

        $this->events->dispatch(new EntityMutated(
            table: 'system_alerts',
            pk: $alert->id,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: $this->storedRow($alert->id),
        ));

        return $alert;
    }

    // One open row per (user, kind), for a fault that stands until somebody
    // acts on it: repeats would bury their own first report, and where an
    // unauthenticated caller can provoke the kind, the repeats ARE the attack.
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

        return $this->raiseForUser($userId, $kind, $severity, $message, $metadata);
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

        $this->events->dispatch(new EntityMutated(
            table: 'system_alerts',
            pk: $alertId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: ['acknowledged_at' => $acknowledgedAt],
        ));
    }

    // The row as the database holds it, minus the id the op already carries
    // as its pk. Read back so `created_at` — a CURRENT_TIMESTAMP default the
    // insert never named — and the JSON metadata text both travel as stored.
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
        unset($fields['id']);

        return $fields;
    }
}
