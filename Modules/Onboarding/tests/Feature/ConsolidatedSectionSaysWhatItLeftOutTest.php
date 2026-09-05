<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Enums\PreviewSectionStatus;

// A file the confirm would refuse is left out whole, so a section holding one
// beside a file that read cleanly is READY on the other one's rows. The count
// under the eyebrow is then lower than what the reader uploaded, with nothing
// on screen saying why — and they are about to commit it.
function consolidatedSectionHtml(?string $error, int $leftOutRunCount = 1): string
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
            leftOutRunCount: $leftOutRunCount,
        )],
    );
}

it('says a ready section left something out when the file was only read part-way', function (): void {
    $html = consolidatedSectionHtml('Row 3: A two digit day could not be found.');

    expect($html)->toContain('One file here was left out, so only the rest will be committed')
        ->and($html)->toContain('Row 3: A two digit day could not be found.')
        ->and($html)->toContain('Albert Heijn');
});

// The count is the point: the line used to say "one of these files" whatever
// the number was, so a reader who dropped six statements and lost three read a
// sentence that accounted for one of them.
it('counts the files it left out rather than always naming one', function (): void {
    expect(consolidatedSectionHtml('The header matched, so the format is right.', leftOutRunCount: 3))
        ->toContain('3 files here were left out, so only the rest will be committed');
});

it('adds nothing to a section that read its file whole', function (): void {
    expect(consolidatedSectionHtml(null, leftOutRunCount: 0))
        ->not->toContain('was left out, so only the rest will be committed');
});

// A row that failed is not a file that was left out. The single `error` field
// carries both, so the section claimed a whole statement had been dropped over
// one unreadable line of a file that is entirely present and about to commit.
it('does not call a failed row a file that was left out', function (): void {
    $html = consolidatedSectionHtml('This row could not be read.', leftOutRunCount: 0);

    expect($html)->toContain('Some rows here could not be read and will be skipped')
        ->and($html)->toContain('This row could not be read.')
        ->and($html)->not->toContain('was left out, so only the rest will be committed');
});
