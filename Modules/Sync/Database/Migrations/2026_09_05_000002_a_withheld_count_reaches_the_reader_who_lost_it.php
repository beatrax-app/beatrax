<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // The count a peer reports for an author it is holding back, kept for
        // EVERY author it names rather than only the ones an identity came
        // with. A withholding nobody can vouch for is the one a reader most
        // needs told about, and it was the one arriving with nowhere to land.
        $this->schema()->create('sync_withheld_history', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('peer_device_id');
            $table->string('author_device_id');
            $table->integer('entry_count');
            $table->text('updated_at');
        });

        $this->db()->connection($this->getConnection())->statement(
            'CREATE UNIQUE INDEX sync_withheld_history_peer_author_idx '
            .'ON sync_withheld_history (user_id, peer_device_id, author_device_id)'
        );

        // The same number in two tables is two answers waiting to disagree, and
        // this one could only ever go stale: it was written beside an offered
        // identity and never rewritten when the peer stopped withholding.
        $this->schema()->table('device_introductions', static function (Blueprint $table): void {
            $table->dropColumn('withheld_entry_count');
        });
    }

    public function down(): void
    {
        $this->schema()->table('device_introductions', static function (Blueprint $table): void {
            $table->integer('withheld_entry_count')->default(0);
        });

        $this->schema()->dropIfExists('sync_withheld_history');
    }
};
