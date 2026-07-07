<?php

declare(strict_types=1);

namespace Modules\Migration\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Single-owner provider for the Migration module.
 *
 * CRITICAL: This provider is the SOLE owner of every Phase 13.5 binding
 * (mirrors the Phase 11 SyncServiceProvider / Phase 13.2 BudgetsServiceProvider
 * single-owner precedent). Downstream plans in this phase never edit this
 * file — their classes are wired here behind `class_exists()` guards,
 * referenced by runtime-built FQCN string so this provider stays parseable
 * and PHPStan-clean before those classes exist. This file is written ONCE in
 * Wave 0 (this plan) and never re-edited by later plans.
 *
 * Service bind inventory (by implementing plan — corrected against the
 * actual Wave execution order; the original Wave-0 guess above numbered
 * these one plan too early for parsers/StartMigrationRun/PreviewSummaryBuilder):
 *   Plan 03: Ynab4Parser / NynabParser (+ Support/ZipExtractor etc.)
 *   Plan 04: ActualParser (+ ActualSqliteReader, not container-registered)
 *   Plan 05: StartMigrationRun, StagingWriter (bounded staging writer),
 *            PreviewSummaryBuilder (singleton read model)
 *   Plan 06: SourceMapWriter, PromoteStagingToDomain (not container-
 *            registered — plain autowired dependency of ConfirmMigration,
 *            mirrors StagingWriter's precedent), ConfirmMigration,
 *            DiscardMigrationRun
 *   Plan 07: CheckForUpdates (reconciliation entry point, incl. any
 *            ThreeWayMergeResolver-shaped internals it needs)
 *   Plan 08: NewMigration / PreviewMigration / MigrationResults / MigrationsIndex
 *            Livewire components + Routes/web.php real routes
 */
final class MigrationServiceProvider extends ServiceProvider
{
    private const PREVIEW_SUMMARY_BUILDER_CLASS = 'Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder';

    private const YNAB4_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\Ynab4Parser';

    private const NYNAB_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\NynabParser';

    private const ACTUAL_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\ActualParser';

    private const START_MIGRATION_RUN_CLASS = 'Modules\Migration\Public\Actions\StartMigrationRun';

    private const CONFIRM_MIGRATION_CLASS = 'Modules\Migration\Public\Actions\ConfirmMigration';

    private const DISCARD_MIGRATION_RUN_CLASS = 'Modules\Migration\Public\Actions\DiscardMigrationRun';

    private const CHECK_FOR_UPDATES_CLASS = 'Modules\Migration\Public\Actions\CheckForUpdates';

    private const SOURCE_MAP_WRITER_CLASS = 'Modules\Migration\Internal\Services\SourceMapWriter';

    /**
     * UAT gap-fix injection point (13.5-HUMAN-UAT.md Test 3c, post-Wave-0):
     * the entity-field-apply/baseline-advance logic shared by
     * `CheckForUpdates` and `ConfirmMigration`'s new take-source
     * conflict-resolution step. Stateless, mirrors `SourceMapWriter`'s own
     * singleton entry above.
     */
    private const ENTITY_CHANGE_APPLIER_CLASS = 'Modules\Migration\Internal\Pipeline\EntityChangeApplier';

    private const MIGRATIONS_INDEX_CLASS = 'Modules\Migration\Internal\Http\Livewire\MigrationsIndex';

    private const NEW_MIGRATION_CLASS = 'Modules\Migration\Internal\Http\Livewire\NewMigration';

    private const PREVIEW_MIGRATION_CLASS = 'Modules\Migration\Internal\Http\Livewire\PreviewMigration';

    private const MIGRATION_RESULTS_CLASS = 'Modules\Migration\Internal\Http\Livewire\MigrationResults';

    public function register(): void
    {
        // Plan 05 injection point: read-model query class, mirrors
        // BudgetProgressQuery/CarryoverQuery's singleton convention.
        $this->singletonIfExists(self::PREVIEW_SUMMARY_BUILDER_CLASS);

        // Plans 03/04 injection point: stateless format parsers, singleton-safe.
        // NOTE: `Internal\Services\ActualSqliteReader` is deliberately NOT
        // registered here — it takes a required per-invocation `$dbPath`
        // constructor argument (the extracted export's file path), which is
        // incompatible with container auto-resolution/singleton reuse.
        // `ActualParser` constructs it directly (`new ActualSqliteReader(...)`)
        // once it knows the extracted path; it is never container-resolved.
        $this->singletonIfExists(self::YNAB4_PARSER_CLASS);
        $this->singletonIfExists(self::NYNAB_PARSER_CLASS);
        $this->singletonIfExists(self::ACTUAL_PARSER_CLASS);

        // Plans 05/06/07 injection points: plain autowired actions/services
        // need no explicit binding (constructor DI resolves them directly),
        // but singletons are registered here where statelessness allows reuse.
        // `Internal\Pipeline\StagingWriter` (Plan 05) is likewise NOT
        // registered here — it has no state and no ctor args requiring a
        // forward-looking guard, so plain container autowiring resolves it
        // as a transient dependency of StartMigrationRun without any entry.
        $this->singletonIfExists(self::SOURCE_MAP_WRITER_CLASS);
        $this->singletonIfExists(self::ENTITY_CHANGE_APPLIER_CLASS);
        $this->singletonIfExists(self::START_MIGRATION_RUN_CLASS);
        $this->singletonIfExists(self::CONFIRM_MIGRATION_CLASS);
        $this->singletonIfExists(self::DISCARD_MIGRATION_RUN_CLASS);
        $this->singletonIfExists(self::CHECK_FOR_UPDATES_CLASS);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'migration');
        }

        // Plan 08 injection points: wizard Livewire components. Registered the
        // moment each class exists on disk, no re-edit of this file needed.
        if (class_exists(self::MIGRATIONS_INDEX_CLASS)) {
            $livewire->component('migration.migrations-index', self::MIGRATIONS_INDEX_CLASS);
        }
        if (class_exists(self::NEW_MIGRATION_CLASS)) {
            $livewire->component('migration.new-migration', self::NEW_MIGRATION_CLASS);
        }
        if (class_exists(self::PREVIEW_MIGRATION_CLASS)) {
            $livewire->component('migration.preview-migration', self::PREVIEW_MIGRATION_CLASS);
        }
        if (class_exists(self::MIGRATION_RESULTS_CLASS)) {
            $livewire->component('migration.migration-results', self::MIGRATION_RESULTS_CLASS);
        }
    }

    /**
     * Register a singleton for a class that may not exist yet (forward-looking
     * injection point for a later plan in this phase). The class name arrives
     * as a runtime-built string so PHPStan does not fold the class_exists()
     * guard to an impossible type. Copied verbatim from
     * Modules\Sync\Providers\SyncServiceProvider::singletonIfExists().
     */
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }
}
