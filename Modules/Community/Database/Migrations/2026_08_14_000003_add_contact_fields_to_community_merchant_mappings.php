<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the optional per-merchant contact / cancellation columns to
 * community_merchant_mappings — the "how do I actually reach or cancel this
 * subscription" half of a corpus row.
 *
 *  - website:       the merchant's official homepage.
 *  - cancel_url:    the deepest page that performs or documents cancellation.
 *  - support_url:   the official help / contact page.
 *  - support_phone: the service number as the merchant itself publishes it.
 *  - support_email: an official support mailbox.
 *
 * Discrete nullable columns rather than one JSON blob: each field has its own
 * validation shape in MerchantContactReader (HTTPS URL vs dialable number vs
 * mailbox), each is rendered as a different affordance, and "which merchants
 * do we still lack a cancel_url for?" stays an ordinary WHERE clause instead
 * of a JSON extraction.
 *
 * The URL columns are 512 rather than the default 255 because a real
 * deep-linked cancellation page frequently carries a locale segment plus a
 * query string; MerchantContactReader rejects anything longer at load time,
 * so a URL is never silently truncated by a lenient driver.
 *
 * Every column is nullable with no default: the overwhelming majority of
 * corpus rows carry no contact data, and an absent field must stay
 * distinguishable from an empty one.
 */
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
