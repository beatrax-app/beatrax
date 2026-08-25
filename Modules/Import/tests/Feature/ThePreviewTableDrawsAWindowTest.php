<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

function previewOfSample(User $user): int
{
    return app(RunsImports::class)->runFromUpload(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-csv',
        $user,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    )->importRunId;
}

function renderedRowCount(string $html): int
{
    return substr_count($html, '<tr data-row-index=');
}

// 229 rows here; a 7 MB statement is 27,777 and drawn whole it was a 46.8 MB
// document. The table draws a window, and the count beside it is the run's, so
// the cap is never silent.
it('draws a window of the rows and says how many the run has', function (): void {
    $html = Livewire::test(PreviewWizard::class, ['id' => previewOfSample($this->fixtureUser)])
        ->assertSee('Rows shown: 100 of 229')
        ->html();

    expect(renderedRowCount($html))->toBe(100);
});

it('grows the window rather than paging away the rows above it', function (): void {
    $component = Livewire::test(PreviewWizard::class, ['id' => previewOfSample($this->fixtureUser)])
        ->call('showMoreRows')
        ->assertSee('Rows shown: 200 of 229');

    expect(renderedRowCount($component->html()))->toBe(200);
});

it('stops offering more once the window holds the whole run', function (): void {
    $html = Livewire::test(PreviewWizard::class, ['id' => previewOfSample($this->fixtureUser)])
        ->call('showMoreRows')
        ->call('showMoreRows')
        ->assertDontSee('Rows shown:')
        ->html();

    expect(renderedRowCount($html))->toBe(229);
});
