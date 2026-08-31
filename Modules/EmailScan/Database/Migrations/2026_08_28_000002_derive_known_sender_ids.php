<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\DerivedRowId;

// A promoted sender now travels between devices, and an autoincrement names a
// different row on the peer. The id is folded from the pair the table's own
// UNIQUE already identifies a row by, so both devices land on the same number
// without exchanging a message.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $derived = [];
        foreach ($connection->table('known_senders')->select('id', 'user_id', 'email_pattern')->orderBy('id')->cursor() as $row) {
            $derived[(int) $row->id] = DerivedRowId::for('known_senders', [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'email_pattern' => is_string($row->email_pattern) ? $row->email_pattern : '',
            ]);
        }

        // Dropping AUTOINCREMENT rebuilds the table, and a SQLite table copy
        // carries neither the indexes nor the `source` trigger pair, so both
        // are recreated after the change.
        $this->schema()->table('known_senders', static function (Blueprint $table): void {
            $table->bigInteger('id')->change();
        });

        foreach ($derived as $old => $new) {
            $connection->table('known_senders')->where('id', $old)->update(['id' => $new]);
        }

        $allowedSources = "'system','user'";

        $connection->statement(sprintf(
            "CREATE TRIGGER known_senders_source_check_insert BEFORE INSERT ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END",
            $allowedSources,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER known_senders_source_check_update BEFORE UPDATE OF source ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END",
            $allowedSources,
        ));
    }

    public function down(): void
    {
        // Deliberately empty: the autoincrement values the derived ids replaced
        // were per-device and are not recoverable.
    }
};
