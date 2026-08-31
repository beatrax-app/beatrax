<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use JsonException;

final readonly class FieldProvenanceWriter
{
    public function __construct(
        private DatabaseManager $db,
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

        $this->db->connection()->update(
            "update transactions set field_provenance = {$expression} where id = ? and user_id = ?",
            $bindings,
        );
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
