<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use JsonException;

/**
 * Shared, race-safe writer/reader for `transactions.field_provenance`
 * (D-04) — a generic per-field manual-vs-rule provenance map consumed
 * by the re-apply-rules manual-edit guard (Req 6/Req 4): a field the
 * user has hand-edited must never be silently overwritten by a rule
 * re-application.
 *
 * Payload shape: `{ "<logical field>": "manual" | "rule" }` — e.g.
 * `{"category_id": "manual", "note": "rule"}`. Canonical logical field
 * keys are `category_id`, `note`, `counterparty_id`, `tax_tag`. IN-02:
 * a third `"import"` state was originally documented but is never
 * stamped by any writer — an absent key already means "not manually
 * set" to every guard that reads this map, which is what `"import"`
 * would have meant too, so the two-state contract above is the actual
 * one.
 *
 * Race safety (T-13.4-12): every stamp is a single DB-side `json_set`
 * UPDATE — never a PHP read-modify-write. Two concurrent stamps to
 * DIFFERENT keys both survive; SQLite's per-row write serialization
 * means the DB, not PHP, owns the merge.
 *
 * Ownership (T-13.4-11): every write and read is scoped by
 * `(id, user_id)` — a foreign or missing transaction id is a silent
 * no-op (0 rows affected) / empty-array read, never a leak.
 */
final class FieldProvenanceWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Merges `$fieldToSource` into the transaction's existing
     * `field_provenance` map via a single, race-safe `json_set` UPDATE.
     * A null (never-stamped) map is initialised via `COALESCE(..., '{}')`
     * before the first key is set. No-op (0 rows) for a foreign/missing
     * transaction id — the `user_id` predicate below is the guard.
     *
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

        $this->db->connection()->update(
            "update transactions set field_provenance = {$expression} where id = ? and user_id = ?",
            $bindings,
        );
    }

    /**
     * Decodes the transaction's `field_provenance` map. Returns `[]` for
     * a never-stamped row, a foreign/missing transaction id, or a
     * corrupt JSON payload — provenance is best-effort audit metadata,
     * never a crash surface.
     *
     * @return array<string, string>
     */
    public function provenanceFor(int $userId, int $transactionId): array
    {
        $raw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('field_provenance');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

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
