<?php

declare(strict_types=1);

namespace Modules\Migration\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Http\Livewire\MigrationResults;
use Modules\Migration\Internal\Http\Livewire\MigrationsIndex;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Internal\Services\SourceMapWriter;

final class MigrationServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(Ynab4Parser::class);
        $this->app->singleton(NynabParser::class);
        $this->app->singleton(ActualParser::class);

        $this->app->singleton(SourceMapWriter::class);
        // Both dispatch the cascade's events, so neither is shared: Event::fake()
        // cannot reach a dispatcher a live singleton already holds, and the map of
        // three already-shared parsers is not state worth a binding for.
        $this->app->bind(StartMigrationRun::class);
        $this->app->bind(DiscardMigrationRun::class);
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
