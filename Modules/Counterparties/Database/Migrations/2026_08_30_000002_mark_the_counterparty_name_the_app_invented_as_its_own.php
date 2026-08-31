<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// A phone set to Dutch read "Onbekend" four times on /counterparties and
// "Unknown" once — on the counterparty row itself, because that one word came
// out of `counterparties.display_name`, where the resolver had written its own
// English as data. Marking the row is what lets the reader's own language win
// on the next read; without this, only rows imported after the fix follow it.
//
// The literals are frozen copies of what the resolver wrote, not imports of
// its constants: what this has to recognise is the wording already on disk.
return new class extends ModuleMigration
{
    private const string METADATA_KEY = 'default_name';

    private const string UNKNOWN = 'unknown';

    public function up(): void
    {
        if (! $this->schema()->hasTable('counterparties')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        // `slug` and `type` are the two plaintext columns on this table, so
        // this reads the same on an encrypted install as on a bare one, where
        // display_name would be a blob. The slug IS the evidence: it is
        // derived from the display name, so slug='unknown' says the stored
        // name was "Unknown". type='unknown' is what makes it the app's own:
        // the triage picker offers merchant, personal, bank and government
        // and nothing else, so a row the reader named cannot still be
        // `unknown` — LabelCounterparty rewrites both columns together.
        $rows = $connection->table('counterparties')
            ->where('type', self::UNKNOWN)
            ->where('slug', self::UNKNOWN)
            ->orderBy('id')
            ->get(['id', 'metadata']);

        $connection->transaction(function () use ($connection, $rows): void {
            foreach ($rows as $row) {
                /** @var stdClass $row */
                $marked = self::marked($row->metadata ?? null);
                if ($marked === null) {
                    continue;
                }

                $connection->table('counterparties')->where('id', $row->id)->update(['metadata' => $marked]);
            }
        });
    }

    public function down(): void
    {
        // Not reversed. Unmarking re-freezes the row in English for every
        // reader who is not in English, which is the defect this repaired, and
        // the mark says nothing that was not already true of the row.
    }

    // Null when the row already carries the mark, so a re-run writes nothing.
    // Every other key on the row is kept: `ignored` is a triage decision the
    // reader made and this must not be what throws it away.
    private static function marked(mixed $stored): ?string
    {
        $metadata = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($metadata)) {
            $metadata = [];
        }

        if (($metadata[self::METADATA_KEY] ?? null) === self::UNKNOWN) {
            return null;
        }

        $metadata[self::METADATA_KEY] = self::UNKNOWN;

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
};
