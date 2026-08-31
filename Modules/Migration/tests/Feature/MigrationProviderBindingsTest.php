<?php

declare(strict_types=1);

use Livewire\Finder\Finder;
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

// A binding written as a runtime-built FQCN string is invisible to static
// analysis, and a typo in one used to be swallowed by a class_exists() guard.
// These pin that the container can still build what the provider names.
//
// Buildable, not identical: a stateless service that dispatches events is
// deliberately not a singleton any more, because Event::fake() cannot reach a
// dispatcher one already holds. ASingletonNeverCapturesTheDispatcher keeps
// that invariant; this keeps the wiring honest.

it('builds every Migration service the provider names', function (string $class): void {
    expect(app($class))->toBeInstanceOf($class);
})->with([
    PreviewSummaryBuilder::class,
    Ynab4Parser::class,
    NynabParser::class,
    ActualParser::class,
    SourceMapWriter::class,
    EntityChangeApplier::class,
    StartMigrationRun::class,
    ConfirmMigration::class,
    DiscardMigrationRun::class,
    CheckForUpdates::class,
]);

it('resolves every Migration Livewire tag to its class', function (string $tag, string $class): void {
    /** @var Finder $finder */
    $finder = app('livewire.finder');

    expect($finder->resolveClassComponentClassName($tag))->toBe($class);
})->with([
    ['migration.migrations-index', MigrationsIndex::class],
    ['migration.new-migration', NewMigration::class],
    ['migration.preview-migration', PreviewMigration::class],
    ['migration.migration-results', MigrationResults::class],
]);
