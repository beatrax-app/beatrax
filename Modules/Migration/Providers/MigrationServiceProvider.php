<?php

declare(strict_types=1);

namespace Modules\Migration\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Http\Livewire\MigrationResults;
use Modules\Migration\Internal\Http\Livewire\MigrationsIndex;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Internal\Services\SourceMapWriter;

final class MigrationServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(PreviewSummaryBuilder::class);

        $this->app->singleton(Ynab4Parser::class);
        $this->app->singleton(NynabParser::class);
        $this->app->singleton(ActualParser::class);

        $this->app->singleton(SourceMapWriter::class);
        $this->app->singleton(EntityChangeApplier::class);
        $this->app->singleton(StartMigrationRun::class);
        $this->app->singleton(ConfirmMigration::class);
        $this->app->singleton(DiscardMigrationRun::class);
        $this->app->singleton(CheckForUpdates::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('migration');

        $livewire->component('migration.migrations-index', MigrationsIndex::class);
        $livewire->component('migration.new-migration', NewMigration::class);
        $livewire->component('migration.preview-migration', PreviewMigration::class);
        $livewire->component('migration.migration-results', MigrationResults::class);
    }
}
