<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\PreviewRowStatus;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->importer = $this->app->make(RunsImports::class);
});

it('parses the tiny ICS PDF fixture through the import pipeline and produces at least one preview row', function (): void {
    $fixture = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $result = $this->importer->runFromUpload(
        $fixture,
        'ics-pdf',
        $this->fixtureUser,
        'ics-sample-tiny.pdf',
    );

    expect(count($result->rows))->toBeGreaterThanOrEqual(1);
    expect(array_filter($result->rows, fn ($r) => $r->status === PreviewRowStatus::NewRow))->not->toBe([]);
})->group('phase-16.1.1');

it('does not emit any ERROR-status rows for the tiny ICS PDF fixture', function (): void {
    $fixture = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $result = $this->importer->runFromUpload(
        $fixture,
        'ics-pdf',
        $this->fixtureUser,
        'ics-sample-tiny.pdf',
    );

    expect(array_filter($result->rows, fn ($r) => $r->status === PreviewRowStatus::Error))->toBe([]);
})->group('phase-16.1.1');
