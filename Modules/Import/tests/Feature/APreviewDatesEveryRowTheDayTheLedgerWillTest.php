<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Support\LedgerDay;

// The ICS statement is the only shipped fixture whose two date columns differ,
// and it is a real one: "Datum transactie" is the day the card was used and
// "Datum boeking" the day the issuer booked it, a different day on 37 of its
// 38 rows and a different MONTH on the row dated 31 jan. / 01 feb.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $fixtureTxt = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt');
    $extractor = new class($fixtureTxt) extends PdfTextExtractor
    {
        public function __construct(private readonly string $fixtureTxt) {}

        public function extract(string $pdfPath): string
        {
            $contents = file_get_contents($this->fixtureTxt);
            if ($contents === false) {
                throw new RuntimeException('Could not read the ICS text fixture.');
            }

            return $contents;
        }
    };

    // The extractor is held behind three singletons, so all three go before the
    // double can be reached. The adapter is not among them: it is not a
    // singleton, so rebuilding the registry builds a new one around the double.
    $this->app->instance(PdfTextExtractor::class, $extractor);
    $this->app->forgetInstance(SourceAdapterRegistry::class);
    $this->app->forgetInstance(ImportPipeline::class);
    $this->app->forgetInstance(ParseStage::class);

    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

/**
 * The Date cell of every preview row, keyed by the row index the markup
 * carries — read off the rendered table rather than off the DTO, because the
 * DTO was right about the value and wrong about which day it held.
 *
 * @return array<int, string>
 */
function previewDateCells(string $html): array
{
    $cells = [];
    $matches = PatternScan::sets('#<tr[^>]*data-row-index="(\d+)"[^>]*>\s*<td[^>]*>(.*?)</td>#s', $html);

    foreach ($matches as $match) {
        $cells[(int) $match[1]] = trim(html_entity_decode(strip_tags($match[2])));
    }

    return $cells;
}

it('shows the reader, for every row of a real card statement, the day the ledger then stores', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload($this->tinyPdf, 'ics-pdf', $this->fixtureUser, 'ics-sample-1.pdf');

    $dates = previewDateCells(Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->html());
    expect(count($dates))->toBe(count($preview->rows));
    expect(count($dates))->toBeGreaterThan(30);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->call('confirm');

    $stored = Transaction::query()->get();
    expect($stored->count())->toBe(count($dates));

    $disagreements = [];
    foreach ($stored as $transaction) {
        $index = (int) $transaction->source_row_index;
        $shown = $dates[$index] ?? '(no row rendered)';
        $stores = LedgerDay::shown($transaction->posted_at);

        if ($shown !== $stores) {
            $disagreements[] = sprintf(
                'row %d (%s): preview showed %s, ledger stores %s',
                $index,
                (string) $transaction->counterparty_name,
                $shown,
                $stores,
            );
        }
    }

    expect($disagreements)->toBe([], implode("\n  ", [
        'The import preview dated rows a day the commit does not write.',
        ...$disagreements,
    ]));
})->group('phase-3');

it('keeps the row that straddles the month turn in the month the ledger files it under', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload($this->tinyPdf, 'ics-pdf', $this->fixtureUser, 'ics-sample-1.pdf');

    $primeVideo = null;
    foreach ($preview->rows as $row) {
        if (str_contains((string) $row->counterpartyName, 'Prime Video')) {
            $primeVideo = $row;
        }
    }

    expect($primeVideo)->not->toBeNull();

    $dates = previewDateCells(Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->html());
    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->call('confirm');

    /** @var Transaction $stored */
    $stored = Transaction::query()->where('counterparty_name', 'LIKE', '%Prime Video%')->firstOrFail();

    // The statement reads "31 jan.  01 feb." — the swipe and the booking fall
    // either side of the turn. January is the answer, on the screen that asks
    // the reader to confirm as much as in the ledger they confirm it into.
    expect($stored->posted_at->toDateString())->toBe('2026-01-31');
    expect($dates[$primeVideo->rowIndex] ?? null)->toBe(LedgerDay::shown($stored->posted_at));
    expect($dates[$primeVideo->rowIndex] ?? null)->not->toBe(LedgerDay::shown('2026-02-01'));
})->group('phase-3');
