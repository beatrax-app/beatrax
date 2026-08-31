<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Enums\PreviewSectionStatus;

// A file that stops being readable part-way still yields rows before the stop,
// so its section is READY. The count under the eyebrow is then simply lower
// than the statement the reader uploaded, with nothing on screen saying why —
// and they are about to commit it.
function consolidatedSectionHtml(?string $error): string
{
    $row = new PreviewRowDto(
        rowIndex: 0,
        status: PreviewRowStatus::NewRow,
        accountId: 1,
        postedAt: '10-05-2026',
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        description: 'groceries',
        amountMinor: -1250,
        currency: 'EUR',
        error: null,
    );

    return Blade::render(
        '<x-onboarding::consolidated-preview-section :section="$section" />',
        ['section' => new ConsolidatedPreviewSection(
            sourceFormat: 'asn-csv',
            importRunIds: [1],
            totalRows: 1,
            sampleRows: [$row],
            status: PreviewSectionStatus::Ready,
            error: $error,
        )],
    );
}

it('says a ready section left something out when the file was only read part-way', function (): void {
    $html = consolidatedSectionHtml('Row 3: A two digit day could not be found.');

    expect($html)->toContain('Some of this file could not be read and was left out')
        ->and($html)->toContain('Row 3: A two digit day could not be found.')
        ->and($html)->toContain('Albert Heijn');
});

it('adds nothing to a section that read its file whole', function (): void {
    expect(consolidatedSectionHtml(null))
        ->not->toContain('Some of this file could not be read and was left out');
});
