<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Discrete nullable columns rather than one JSON blob: each field has its own
// validation shape in MerchantContactReader, and "which merchants still lack a
// cancel_url?" stays an ordinary WHERE clause. The URL columns are 512, not the
// default 255 — MerchantContactReader drops anything longer at load time.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('community_merchant_mappings', static function (Blueprint $table): void {
            $table->string('website', 512)->nullable()->after('contributor');
            $table->string('cancel_url', 512)->nullable()->after('website');
            $table->string('support_url', 512)->nullable()->after('cancel_url');
            $table->string('support_phone', 32)->nullable()->after('support_url');
            $table->string('support_email', 255)->nullable()->after('support_phone');
        });
    }

    public function down(): void
    {
        $this->schema()->table('community_merchant_mappings', static function (Blueprint $table): void {
            $table->dropColumn(['website', 'cancel_url', 'support_url', 'support_phone', 'support_email']);
        });
    }
};
