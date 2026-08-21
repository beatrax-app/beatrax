<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('exchange_rates', static function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->date('rate_date');
            // Same precision as transactions.fx_rate_used, so a cached rate and
            // a settled one are never a truncation away from equal.
            $table->decimal('rate', 18, 8);
            $table->string('source', 20);
            $table->timestamps();

            // Source is part of the key so ECB, Frankfurter and the bundled
            // snapshot coexist, and each one's upsert stays idempotent.
            $table->unique(
                ['base_currency', 'quote_currency', 'rate_date', 'source'],
                'exchange_rates_dated_unique',
            );
            $table->index(
                ['base_currency', 'quote_currency', 'rate_date'],
                'exchange_rates_latest_lookup',
            );
            $table->index(
                ['quote_currency', 'rate_date'],
                'exchange_rates_inverse_lookup',
            );
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('exchange_rates');
    }
};
