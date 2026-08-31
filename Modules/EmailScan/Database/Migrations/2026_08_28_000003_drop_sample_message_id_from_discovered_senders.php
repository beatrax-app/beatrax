<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Discovery never had a message row to point at: DiscoveryScanJob walks sender
// headers and writes no inbox_messages, so the column held the demo seeder's
// value and null everywhere else, behind a belongsTo that could only ever
// answer null. Dropped rather than filled — see the down() for what it was.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('discovered_senders', static function (Blueprint $table): void {
            $table->dropForeign(['sample_message_id']);
            $table->dropColumn('sample_message_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('discovered_senders', static function (Blueprint $table): void {
            $table->foreignId('sample_message_id')->nullable()->constrained('inbox_messages')->nullOnDelete();
        });
    }
};
