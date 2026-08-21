<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('op_log_quarantine', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('op_entry_id')->nullable();
            $table->string('table_name');
            $table->string('pk');
            $table->string('device_id');
            $table->string('reason');
            $table->unsignedBigInteger('hlc_l')->nullable();
            $table->unsignedInteger('hlc_c')->nullable();
            $table->text('raw_value')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $connection = $this->db()->connection($this->getConnection());

        // DESC in the index, matching the sync-health page's
        // ->orderByDesc('created_at') so the hot path is a range scan.
        $connection->statement(
            'CREATE INDEX op_log_quarantine_user_idx ON op_log_quarantine (user_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('op_log_quarantine');
    }
};
