<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Sync\Internal\Clock\ZuluTimestamp;

return new class extends ModuleMigration
{
    private const string ZULU_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    // Rewritten rather than deleted, unlike the pairing_tokens sweep beside
    // this file: device_registry is the permanent trust store — dropping a row
    // un-pairs both devices and costs the user a fresh safety-number ceremony —
    // and sync_sessions is the history the status panel reads.
    public function up(): void
    {
        foreach ($this->stringStampColumns() as $table => $columns) {
            if (! $this->schema()->hasTable($table)) {
                continue;
            }

            $this->rewriteTable($table, $columns);
        }
    }

    // The instant each value names is preserved, only its rendering changes,
    // so there is nothing a rollback could restore — and the local offset the
    // value was first written at is recorded nowhere to restore it from.
    public function down(): void {}

    /**
     * TEXT columns, so SQL sorts them as strings: confirmedDevices() orders by
     * device_registry.paired_at and peerStatuses() by sync_sessions.last_seen_at.
     * A row left at a local offset sorts by its own hour digits against every
     * Zulu row written from here on.
     *
     * @return array<string, list<string>>
     */
    private function stringStampColumns(): array
    {
        return [
            'device_registry' => ['paired_at', 'confirmed_at', 'created_at', 'updated_at', 'last_seen_at'],
            'sync_sessions' => ['connected_at', 'created_at', 'updated_at', 'last_seen_at'],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    private function rewriteTable(string $table, array $columns): void
    {
        $connection = $this->db()->connection($this->getConnection());

        foreach ($connection->table($table)->select(['id', ...$columns])->get() as $row) {
            $changes = [];

            foreach ($columns as $column) {
                $rewritten = $this->asZulu($row->{$column} ?? null);

                if ($rewritten !== null) {
                    $changes[$column] = $rewritten;
                }
            }

            if ($changes !== []) {
                $connection->table($table)->where('id', $row->id)->update($changes);
            }
        }
    }

    // Null for a value that is already Zulu, empty, or not a timestamp at all:
    // a stamp nothing can parse is left exactly as found rather than replaced
    // with a guess at what it meant.
    private function asZulu(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || preg_match(self::ZULU_PATTERN, $value) === 1) {
            return null;
        }

        try {
            return ZuluTimestamp::stamp(CarbonImmutable::parse($value));
        } catch (Throwable) {
            return null;
        }
    }
};
