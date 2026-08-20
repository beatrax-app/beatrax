<?php

declare(strict_types=1);

namespace Modules\Migration\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;

final class MigrationServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    private const PREVIEW_SUMMARY_BUILDER_CLASS = 'Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder';

    private const YNAB4_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\Ynab4Parser';

    private const NYNAB_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\NynabParser';

    private const ACTUAL_PARSER_CLASS = 'Modules\Migration\Internal\Parsers\ActualParser';

    private const START_MIGRATION_RUN_CLASS = 'Modules\Migration\Public\Actions\StartMigrationRun';

    private const CONFIRM_MIGRATION_CLASS = 'Modules\Migration\Public\Actions\ConfirmMigration';

    private const DISCARD_MIGRATION_RUN_CLASS = 'Modules\Migration\Public\Actions\DiscardMigrationRun';

    private const CHECK_FOR_UPDATES_CLASS = 'Modules\Migration\Public\Actions\CheckForUpdates';

    private const SOURCE_MAP_WRITER_CLASS = 'Modules\Migration\Internal\Services\SourceMapWriter';

    private const ENTITY_CHANGE_APPLIER_CLASS = 'Modules\Migration\Internal\Pipeline\EntityChangeApplier';

    private const MIGRATIONS_INDEX_CLASS = 'Modules\Migration\Internal\Http\Livewire\MigrationsIndex';

    private const NEW_MIGRATION_CLASS = 'Modules\Migration\Internal\Http\Livewire\NewMigration';

    private const PREVIEW_MIGRATION_CLASS = 'Modules\Migration\Internal\Http\Livewire\PreviewMigration';

    private const MIGRATION_RESULTS_CLASS = 'Modules\Migration\Internal\Http\Livewire\MigrationResults';

    public function register(): void
    {
        $this->singletonIfExists(self::PREVIEW_SUMMARY_BUILDER_CLASS);

        $this->singletonIfExists(self::YNAB4_PARSER_CLASS);
        $this->singletonIfExists(self::NYNAB_PARSER_CLASS);
        $this->singletonIfExists(self::ACTUAL_PARSER_CLASS);

        $this->singletonIfExists(self::SOURCE_MAP_WRITER_CLASS);
        $this->singletonIfExists(self::ENTITY_CHANGE_APPLIER_CLASS);
        $this->singletonIfExists(self::START_MIGRATION_RUN_CLASS);
        $this->singletonIfExists(self::CONFIRM_MIGRATION_CLASS);
        $this->singletonIfExists(self::DISCARD_MIGRATION_RUN_CLASS);
        $this->singletonIfExists(self::CHECK_FOR_UPDATES_CLASS);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('migration');

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

    private function singletonIfExists(string $class): void
    {
        // $class is a runtime-built FQCN string, never a ::class literal, so static
        // analysis cannot fold this guard away for a class not yet on disk.
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }
}
