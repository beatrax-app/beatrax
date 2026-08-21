<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// account_uid is an opaque aggregator-assigned account identifier, not
// credential material, so unlike the session it belongs to it may live in a
// column. One row holds one account: the callback persists accounts[0] only.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->string('account_uid', 128)->nullable()->after('institution_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->dropColumn('account_uid');
        });
    }
};
