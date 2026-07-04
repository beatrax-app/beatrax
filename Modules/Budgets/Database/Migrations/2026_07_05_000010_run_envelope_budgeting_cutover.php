<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\Budgets\Public\Services\EnvelopeActivationService;

/**
 * The D-13 hard cutover, run once at migrate time. Resolves
 * `EnvelopeActivationService` and runs it — all archive/stamp logic lives in
 * the service (Plan 06 Task 1); this migration is deliberately thin.
 *
 * Runs AFTER 2026_07_05_000004 (the `users.envelope_activated_at` genesis
 * column) so the column the service stamps already exists.
 *
 * Forward-only by design (mirrors the
 * `2026_05_16_010004_backpopulate_card_statements_from_statement_summaries`
 * precedent): `down()` is a safe no-op. This is a hard, irreversible cutover
 * (D-13) — un-archiving pots or clearing `envelope_activated_at` would
 * silently resurrect a data shape the rest of Phase 13.2 no longer supports.
 * `EnvelopeActivationService::activate()` is itself idempotent, so re-running
 * `up()` (e.g. via `migrate:refresh` in dev) is always a safe no-op for
 * already-cutover users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Container::getInstance()->make(EnvelopeActivationService::class)->activate();
    }

    public function down(): void
    {
        // Forward-only — see class docblock. Rolling back the cutover would
        // silently resurrect category-linked pots and un-stamp the genesis
        // anchor for already-activated users; neither is safe.
    }
};
