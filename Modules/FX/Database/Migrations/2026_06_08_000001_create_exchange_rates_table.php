<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the exchange_rates table — the local dated rate cache.
 *
 * Rates are keyed by (base_currency, quote_currency, rate_date, source) so
 * every fetch path (ECB, Frankfurter, bundled snapshot) can coexist in the
 * table and the upsert on that four-column unique index is idempotent (D-10).
 *
 * Rate precision mirrors `transactions.fx_rate_used` exactly (DECIMAL 18,8)
 * so round-trip comparisons between stored rates and transaction rates are
 * always exact — never subject to float truncation (T-01-01 threat, Pitfall 1).
 *
 * Three indexes:
 *  - UNIQUE (base, quote, rate_date, source)   → idempotent upsert key
 *  - INDEX  (base, quote, rate_date)            → latest-rate lookup
 *  - INDEX  (quote, rate_date)                  → inverse cross-rate lookup
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('exchange_rates', static function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source', 20);
            $table->timestamps();

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
