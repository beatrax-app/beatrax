<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// expandedRowCount is the per-section row slice the consolidated preview is
// built with, and loadMoreRows() is its only writer. Unlocked it reached the
// query's LIMIT untouched, with no ceiling on the other side, so a payload
// naming a million drew every committable row of a statement into one fragment
// on the phone that has to render it. PreviewWizard::$visibleRows is locked
// against exactly this, and the ceiling now stands whatever arrives.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'expanded-rows-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function firstImportStepSnapshot(): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) Livewire::mount('onboarding.steps.first-import-step'),
        'onboarding.steps.first-import-step',
    );
}

it('refuses a payload that names its own preview row count', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        firstImportStepSnapshot(),
        ['expandedRowCount' => ['camt053' => 1_000_000]],
    )->assertForbidden();
});

it('refuses a payload that names one section row count', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        firstImportStepSnapshot(),
        ['expandedRowCount.camt053' => 1_000_000],
    )->assertForbidden();
});

it('still grows the slice through loadMoreRows', function (): void {
    $response = LivewireRoundTrip::tamper(
        $this,
        firstImportStepSnapshot(),
        [],
        [['path' => '', 'method' => 'loadMoreRows', 'params' => ['camt053']]],
    )->assertOk();

    $snapshot = (string) $response->json('components.0.snapshot');

    expect($snapshot)->toContain((string) (BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT + 25));
});
