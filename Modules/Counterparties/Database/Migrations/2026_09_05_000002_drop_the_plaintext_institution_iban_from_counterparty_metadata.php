<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// The known-IBAN bridge arm stamped `metadata.institution_iban` beside the
// bridge kind. `counterparties.metadata` is on no encryption list, so that was
// the row's own sealed `iban` written in the clear one column over, and nothing
// ever read it back. The resolver no longer writes it; this clears the rows
// that already carry it. Plaintext JSON, so it needs no key and no unlock.
return new class extends ModuleMigration
{
    private const string LEAKED_KEY = 'institution_iban';

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
        // Not reversed. The value is recoverable from the row's own `iban`
        // column, and restoring it would restore a cleartext copy of it.
    }

    // Null when this row has nothing to strip, so a re-run writes nothing and
    // no other key is disturbed: `ignored` is a triage decision the reader
    // made and `default_name` says whose words the display name is.
    private static function withoutTheLeakedKey(mixed $stored): ?string
    {
        $metadata = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($metadata) || ! array_key_exists(self::LEAKED_KEY, $metadata)) {
            return null;
        }

        unset($metadata[self::LEAKED_KEY]);

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
};
