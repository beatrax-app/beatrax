<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// A fold recorded what it absorbed as `metadata.merged_from[].name`, taken from
// the absorbed row's `display_name` after decrypting it. `counterparties`
// .metadata is on no encryption list and `display_name` is sealed, so that was
// the sealed value in the clear one column over -- the institution_iban shape,
// in the same column. The absorbed row is deleted by the same fold, so the copy
// outlived the column it was sealed in. The action now records the slug only;
// this clears the rows that already carry a name. Plaintext JSON, so it needs
// no key and no unlock.
return new class extends ModuleMigration
{
    private const string DOES_NOT_ANNOUNCE = 'same-on-every-device: one key is removed from each merged_from entry of the metadata JSON on the row, so a device holding that row removes the same key and reaches the same value.';

    private const string PROVENANCE_KEY = 'merged_from';

    private const string LEAKED_KEY = 'name';

    public function up(): void
    {
        if (! $this->schema()->hasTable('counterparties')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        $rows = $connection->table('counterparties')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->get(['id', 'metadata']);

        $connection->transaction(function () use ($connection, $rows): void {
            foreach ($rows as $row) {
                $stripped = self::withoutTheLeakedKey($row->metadata ?? null);
                if ($stripped === null) {
                    continue;
                }

                $connection->table('counterparties')->where('id', $row->id)->update(['metadata' => $stripped]);
            }
        });
    }

    public function down(): void
    {
        // Not reversed. The absorbed row is gone, so the name is recoverable
        // from nothing, and restoring it would restore a cleartext copy of a
        // value this column is not keyed to hold.
    }

    // Null when this row has nothing to strip, so a re-run writes nothing and
    // no other key is disturbed: `slug` is what the entry is kept for and
    // `moved` is how many rows it carried across.
    private static function withoutTheLeakedKey(mixed $stored): ?string
    {
        $metadata = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($metadata) || ! is_array($metadata[self::PROVENANCE_KEY] ?? null)) {
            return null;
        }

        $entries = [];
        $found = false;

        foreach ($metadata[self::PROVENANCE_KEY] as $entry) {
            if (is_array($entry) && array_key_exists(self::LEAKED_KEY, $entry)) {
                unset($entry[self::LEAKED_KEY]);
                $found = true;
            }

            $entries[] = $entry;
        }

        if (! $found) {
            return null;
        }

        $metadata[self::PROVENANCE_KEY] = $entries;

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
};
