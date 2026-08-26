<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Both fixtures are the shapes a real bank export arrives in when it goes
// wrong: one whose header row belongs to a different bank entirely, and one
// that reads fine until a single unparseable date stops the reader mid-file.
// The second is the case that matters — the reader is about to confirm an
// import that is silently missing everything after the bad row.
const PREVIEW_UNREADABLE_FIXTURE = __DIR__.'/../../../../tests/fixtures/unrecognised-headers.csv';

const PREVIEW_PARTIAL_FIXTURE = __DIR__.'/../../../../tests/fixtures/asn-partial-failure.csv';

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

function previewOf(User $user, string $fixture): ImportPreviewResult
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    return $importer->runFromUpload(
        $fixture,
        'asn-csv',
        $user,
        basename($fixture),
        BankCsvFormatHint::Asn,
    );
}

// A file the format check accepts and the reader then chokes on, built here
// rather than committed: the header has to be the real one for the sniff to
// pass, and the point of each file is the one cell that differs from it.
function asnFileWith(string ...$rows): string
{
    $lines = file(base_path('tests/fixtures/asn-sample-1.csv'));
    $path = sys_get_temp_dir().'/beatrax-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, rtrim((string) $lines[0], "\r\n")."\n".implode("\n", $rows)."\n");

    return $path;
}

function asnRowWithAmount(string $amount): string
{
    $lines = file(base_path('tests/fixtures/asn-sample-1.csv'));
    $cells = str_getcsv(rtrim((string) $lines[1], "\r\n"), ',', '"', '');
    $cells[10] = $amount;

    return implode(',', $cells);
}

// Blade breaks the button's attributes over several lines, so the presence of
// @disabled is only readable once the whitespace between them is flattened.
function confirmButtonMarkup(int $importRunId): string
{
    $html = Livewire::test(PreviewWizard::class, ['id' => $importRunId])->html();

    return (string) preg_replace('/\s+/', ' ', $html);
}

it('reports a file it could not read as a failure rather than as one row of dashes', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_UNREADABLE_FIXTURE);

    expect($preview->rows)->toBe([]);
    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('This file could not be read')
        ->assertSee('header row that does not match the source you chose')
        ->assertSee('This file does not match the ASN CSV layout.')
        ->html();

    // The table is the assertion that matters: it rendered one row whose every
    // cell was an em-dash, and a dash in a cell says "this row exists, with no
    // value here". No row exists, so no table.
    expect($html)->not->toContain('Funding source');
});

it('does not offer to confirm an import that would write nothing', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_UNREADABLE_FIXTURE);

    expect(confirmButtonMarkup($preview->importRunId))->toContain('wire:click="confirm" disabled');

    // The disabled attribute is a DOM guard and devtools defeats it, so the
    // wire call is the one that has to refuse.
    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertNoRedirect();

    expect(ImportRun::query()->find($preview->importRunId)?->status)->toBe('previewed');
    expect(Transaction::query()->count())->toBe(0);
});

it('says a file was only read part-way instead of presenting the truncated import as complete', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_PARTIAL_FIXTURE);

    // Six data rows in the file; the fourth stops the reader, so three arrive
    // and rows five and six are absent rather than present-and-failed.
    expect($preview->rows)->toHaveCount(3);
    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileStoppedShort);
    foreach ($preview->rows as $row) {
        expect($row->status)->toBe(PreviewRowStatus::NewRow);
    }

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('Only part of this file could be read')
        ->assertSee('Anything after that point was not read and will not be imported.')
        ->assertSee('Rows read');
});

it('still offers to confirm the rows a part-read file did yield', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_PARTIAL_FIXTURE);

    expect(confirmButtonMarkup($preview->importRunId))->not->toContain('wire:click="confirm" disabled');

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertRedirect();

    expect(Transaction::query()->count())->toBe(3);
});

// chain_resolution_runs.last_error is written as "<JobClass>: <first line of
// the message>". That is a developer's sentence wherever it comes from, and
// the crypto layer's version of it names an internal class and the reader's
// own user id. The Horizon link beside it is the developer's door.
it('does not print a failed job class name into the chain-resolution notice', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_PARTIAL_FIXTURE);

    ChainResolutionRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'status' => 'failed',
        'last_error' => 'ResolveChainLinksJob: BlindIndexCodec: encryption is enabled for user 1',
    ]);

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSee('the details are in the job log')
        ->html();

    expect($html)->not->toContain('ResolveChainLinksJob')
        ->and($html)->not->toContain('BlindIndexCodec');
});

// The first data row failing left zero preview rows, and the screen then read
// "Nothing in this file could be read" over advice to check the bank, check the
// format, and re-download the statement. HeaderSniffer had already agreed the
// header matched, so all three sent the reader after a file that was fine.
it('does not blame the header for a row that failed after the header was accepted', function (): void {
    $preview = previewOf($this->fixtureUser, asnFileWith(asnRowWithAmount('not-a-number')));

    expect($preview->rows)->toBe([])
        ->and($preview->fileFailureReason)->toBe(ImportFailureReason::FileStoppedShort);

    $html = Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('This file could not be read')
        ->assertSee('The header matched, so the format is right')
        ->html();

    expect($html)->not->toContain('header row that does not match');
});

// The control for the pair above: when the format check is what refused the
// file, the header advice is the right advice and has to survive.
it('still blames the header when the format check is what refused the file', function (): void {
    $preview = previewOf($this->fixtureUser, PREVIEW_UNREADABLE_FIXTURE);

    expect($preview->fileFailureReason)->toBe(ImportFailureReason::FileUnreadable);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('header row that does not match the source you chose');
});
