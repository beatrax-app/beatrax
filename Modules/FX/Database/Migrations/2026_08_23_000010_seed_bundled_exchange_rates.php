<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\FX\Internal\Services\SeedBundledExchangeRates;

// The bundled snapshot ships with the app and is the fallback the online-fetch
// column was added with, but FetchFxRatesJob — the only writer — returns before
// it whenever the reader has not opted into network fetches, which is the
// default. So exchange_rates stayed empty on every install that stayed offline.
return new class extends Migration
{
    public function up(): void
    {
        /** @var SeedBundledExchangeRates $service */
        $service = Container::getInstance()->make(SeedBundledExchangeRates::class);

        $service->run();
    }

    public function down(): void
    {
        // Data-only and idempotent: the rows are keyed on source, so re-running
        // up() rewrites its own and touches no provider's.
    }
};
