<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Sync\Public\Events\TransactionMutated;

final readonly class FieldProvenanceWriter
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    // A null (never-stamped) map is initialised via COALESCE(..., '{}')
    // before the first key is set. No-op for a foreign/missing
    // transaction id — the user_id predicate below is the guard.
    /**
     * @param  array<string, string>  $fieldToSource
     */
    public function stamp(int $userId, int $transactionId, array $fieldToSource): void
    {
        if ($fieldToSource === []) {
            return;
        }

        $expression = "COALESCE(field_provenance, '{}')";
        $bindings = [];

        foreach ($fieldToSource as $field => $source) {
            $expression = "json_set({$expression}, ?, ?)";
            $bindings[] = '$.'.$field;
            $bindings[] = $source;
        }

        $bindings[] = $transactionId;
        $bindings[] = $userId;

        $before = $this->provenanceFor($userId, $transactionId);

        $affected = $this->db->connection()->update(
            "update transactions set field_provenance = {$expression} where id = ? and user_id = ?",
            $bindings,
        );

        // A foreign or missing transaction id changes no row, and announcing
        // a stamp that did not happen would hand a peer a map for a row this
        // device never wrote.
        if ($affected < 1) {
            return;
        }

        $this->announce($userId, $transactionId, $before);
    }

    // A re-apply skips any field the reader set by hand, and that promise is
    // made of the person, not of one device. Until this was announced the
    // column travelled only in the create payload, where it is always null,
    // so the protection held where it was typed and nowhere else.
    /**
     * @param  array<string, string>  $before
     */
    private function announce(int $userId, int $transactionId, array $before): void
    {
        // The merged map, read back rather than the delta: the UPDATE above
        // wraps json_set around whatever was stored, so the argument to this
        // call is only the keys that moved. A peer given those would drop
        // every key it had not just been told about.
        $merged = $this->provenanceFor($userId, $transactionId);

        // A stamp that rewrote the value already stored moved nothing a peer
        // has to hear about, and a re-apply run is expected to be idempotent.
        if ($merged === [] || $merged === $before) {
            return;
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: ['field_provenance' => $merged],
        ));
    }

    // Returns [] for a never-stamped row, a foreign/missing transaction
    // id, or corrupt JSON — provenance is best-effort audit metadata,
    // never a crash surface.
    /**
     * @return array<string, string>
     */
    public function provenanceFor(int $userId, int $transactionId): array
    {
        $raw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('field_provenance');

        return is_string($raw) && $raw !== '' ? self::decodeProvenance($raw) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function decodeProvenance(string $raw): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, string> $decoded */
        return $decoded;
    }
}
