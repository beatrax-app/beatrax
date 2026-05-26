<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;

/*
 * Regression test for the ICS PDF upload path through the import
 * preview pipeline.
 *
 * Pins the working preview contract against future refactors of the
 * IcsPdfAdapter, the spatial extractor, the PaymentType classifier,
 * or the Livewire upload temp-file resolution. If any of those layers
 * regresses such that an ICS PDF upload produces zero preview rows
 * (silent parser drop) or every row carrying status='error' (loud
 * parser drop), this test fails.
 */

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
    expect(array_filter($result->rows, fn ($r) => $r->status === 'new'))->not->toBe([]);
})->group('phase-16.1.1');

it('does not emit any ERROR-status rows for the tiny ICS PDF fixture', function (): void {
    $fixture = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $result = $this->importer->runFromUpload(
        $fixture,
        'ics-pdf',
        $this->fixtureUser,
        'ics-sample-tiny.pdf',
    );

    expect(array_filter($result->rows, fn ($r) => $r->status === 'error'))->toBe([]);
})->group('phase-16.1.1');
