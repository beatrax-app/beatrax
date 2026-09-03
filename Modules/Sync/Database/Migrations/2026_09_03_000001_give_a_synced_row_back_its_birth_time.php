<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection();

        // Rows that arrived from a build whose capture did not name created_at
        // were written without one, and the device that re-captures such a row
        // sends the null on. The lists that order by it put a null LAST however
        // new the row is, and past their limit drop it from the page.
        $tables = $connection->table('op_log_entries')
            ->where('op_type', 'create_row')
            ->distinct()
            ->pluck('table_name');

        foreach ($tables as $table) {
            if (! is_string($table) || ! $this->schema()->hasTable($table) || ! $this->schema()->hasColumn($table, 'created_at')) {
                continue;
            }

            $this->repair($table);
        }
    }

    // The op's own HLC, whose high half is a wall clock in milliseconds: the
    // earliest moment this device can prove the row existed. Applied one row at
    // a time because the answer differs per row and SQLite has no join in
    // UPDATE; the set is the rows that are wrong, which is small.
    private function repair(string $table): void
    {
        $connection = $this->db()->connection();

        $undated = $connection->table($table)->whereNull('created_at')->pluck('id');

        foreach ($undated as $id) {
            $earliest = $connection->table('op_log_entries')
                ->where('table_name', $table)
                ->where('pk', (string) $id)
                ->where('op_type', 'create_row')
                ->min('hlc_l');

            if (! is_numeric($earliest) || (int) $earliest <= 0) {
                continue;
            }

            $stamp = date('Y-m-d H:i:s', (int) ((int) $earliest / 1000));

            $connection->table($table)->where('id', $id)->update(array_filter([
                'created_at' => $stamp,
                'updated_at' => $this->schema()->hasColumn($table, 'updated_at') ? $stamp : null,
            ], static fn (?string $value): bool => $value !== null));
        }
    }

    // Deliberately irreversible: down() would have to put back a null that was
    // never information, and the rows it would blank are no longer identifiable.
    public function down(): void {}
};
