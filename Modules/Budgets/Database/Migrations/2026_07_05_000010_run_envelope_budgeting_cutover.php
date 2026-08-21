<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\Budgets\Public\Services\EnvelopeActivationService;

// Ordered after the `users.envelope_activated_at` migration, since the
// service stamps that column. `activate()` is idempotent, so re-running
// `up()` is a no-op for already-cutover users.
return new class extends Migration
{
    public function up(): void
    {
        Container::getInstance()->make(EnvelopeActivationService::class)->activate();
    }

    public function down(): void
    {
        // Forward-only: rolling back would resurrect category-linked pots and
        // un-stamp the genesis anchor for already-activated users.
    }
};
