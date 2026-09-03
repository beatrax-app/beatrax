<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // transaction_splits declared no identity but its autoincrement, and
        // sort_order is reassigned on every save, so a leg had nothing two
        // devices could agree on. Minted once by the device that adds the leg
        // and never rewritten, so an edit or a reorder leaves it alone.
        $this->schema()->table('transaction_splits', static function (Blueprint $table): void {
            $table->string('split_uuid')->nullable();
            $table->index('split_uuid');
        });

        // Derived from columns the devices already agree on, so a row that has
        // synced gets the same value on each of them without exchanging one.
        // Minting a uuid here would give the same leg a different identity per
        // device, which is the problem this column exists to end.
        foreach ($this->db()->connection()->table('transaction_splits')->get(['id', 'transaction_id']) as $row) {
            $this->db()->connection()->table('transaction_splits')
                ->where('id', $row->id)
                ->update(['split_uuid' => 'legacy:'.substr(hash('sha256', $row->transaction_id.':'.$row->id), 0, 32)]);
        }
    }

    public function down(): void
    {
        $this->schema()->table('transaction_splits', static function (Blueprint $table): void {
            $table->dropIndex(['split_uuid']);
            $table->dropColumn('split_uuid');
        });
    }
};
