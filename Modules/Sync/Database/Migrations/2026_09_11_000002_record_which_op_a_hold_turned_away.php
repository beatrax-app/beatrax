<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/architecture.md#an-id-whose-row-never-landed
 */
return new class extends ModuleMigration
{
    private const string INDEX = 'op_log_quarantine_row_idx';

    // Written as literals rather than read off QuarantineReason: the backing
    // values are the on-disk contract, and a migration that re-reads the enum
    // would re-describe rows it already stamped if a case were ever renamed.
    private const array REFUSED_WHILE_INSERTING = [
        'incomplete_create_row',
        'missing_reference',
        'primary_key_collision',
        'unplaceable_collision',
    ];

    // A hold says why an op was refused and never said WHICH op, so nothing
    // could tell a create that reached no row from an edit to a row sitting
    // right there. The gate asking whether a peer's id names a row here needs
    // exactly that, addressed by (device, table, pk) — hence the index too.
    public function up(): void
    {
        if (! $this->schema()->hasTable('op_log_quarantine')) {
            return;
        }

        if (! $this->schema()->hasColumn('op_log_quarantine', 'op_type')) {
            $this->schema()->table('op_log_quarantine', static function (Blueprint $table): void {
                $table->string('op_type')->nullable()->after('device_id');
            });
        }

        $connection = $this->db()->connection($this->getConnection());

        // Only the four verdicts no field op can carry. Every other reason is
        // recorded on both paths, so a hold an older build wrote under one
        // stays null and the gate reads it as an answer it does not have.
        $connection->table('op_log_quarantine')
            ->whereNull('op_type')
            ->whereIn('reason', self::REFUSED_WHILE_INSERTING)
            ->update(['op_type' => 'create_row']);

        $connection->statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON op_log_quarantine (user_id, device_id, table_name, pk)'
        );
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('op_log_quarantine')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement('DROP INDEX IF EXISTS '.self::INDEX);

        $this->schema()->table('op_log_quarantine', static function (Blueprint $table): void {
            $table->dropColumn('op_type');
        });
    }
};
