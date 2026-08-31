<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

it('imports every parsed row from the redacted .txt fixture on the first run via the test-double extractor', function (): void {
    // The tiny synthetic .pdf drives real pdftotext + HeaderSniffer; the
    // redacted-text fixture is covered in Ingestion's IcsPdfAdapterTest.
    $result = $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    expect($result->inserted)->toBeGreaterThan(0);
    expect(Transaction::count())->toBe($result->inserted);
})->group('phase-3');

it('returns zero new rows when re-importing the same SHA-256 tiny PDF', function (): void {
    $first = $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
})->group('phase-3');

it('returns zero new rows when re-importing a different SHA but identical content', function (): void {
    $bytes = file_get_contents($this->tinyPdf);
    if ($bytes === false) {
        throw new RuntimeException('Could not read tiny PDF fixture.');
    }

    // Bumping the xref placeholder for object 0 changes the SHA-256 without
    // touching the extracted text — pdftotext never dereferences it — so what
    // catches the re-import is fingerprint-v3 dedup, not the file hash.
    $needle = '0000000000 65535 f';
    $mutated = str_replace($needle, '0000000001 65535 f', $bytes);
    if ($mutated === $bytes) {
        throw new RuntimeException('Could not locate xref padding to mutate.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ics-shadup').'.pdf';
    file_put_contents($tmp, $mutated);

    try {
        $first = $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);
        $second = $this->importer->runAndConfirm($tmp, 'ics-pdf', $this->fixtureUser);

        expect($first->inserted)->toBeGreaterThan(0);
        expect($second->inserted)->toBe(0);
        expect($second->duplicates)->toBe($first->inserted);
    } finally {
        @unlink($tmp);
    }
})->group('phase-3');

it('persists settled_amount_minor and settled_currency for an EUR-native row', function (): void {
    $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('counterparty_name', 'LIKE', '%SYNTHETIC%')->firstOrFail();

    expect($tx->currency)->toBe('EUR');
    expect($tx->amount_minor)->toBe(-100);
    expect($tx->settled_amount_minor)->toBe(-100);
    expect($tx->settled_currency)->toBe('EUR');
    expect($tx->fx_rate_used)->toBeNull();
})->group('phase-3');

it('persists native + settled + fx_rate_used for a foreign-currency row', function (): void {
    // The tiny synthetic PDF has only an EUR-native row, so the extractor is
    // doubled out for the redacted .txt fixture, which carries three real FX
    // rows (Augment Code USD/EUR, Audible UK GBP/EUR, Vitrus USD/EUR).
    $fixtureTxt = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt');
    $extractorDouble = new class($fixtureTxt) extends PdfTextExtractor
    {
        public function __construct(private readonly string $fixtureTxt)
        {
            parent::__construct();
        }

        public function extract(string $pdfPath): string
        {
            $contents = file_get_contents($this->fixtureTxt);
            if ($contents === false) {
                throw new RuntimeException('Could not read ICS text fixture.');
            }

            return $contents;
        }
    };
    $this->app->instance(PdfTextExtractor::class, $extractorDouble);
    // The adapter is reachable transitively from three other singletons
    // (registry -> pipeline, registry -> parse stage), so all of them have to
    // go before the doubled extractor can reach it.
    $this->app->forgetInstance(SourceAdapterRegistry::class);
    $this->app->forgetInstance(IcsPdfAdapter::class);
    $this->app->forgetInstance(ImportPipeline::class);
    $this->app->forgetInstance(ParseStage::class);

    $importer = $this->app->make(RunsImports::class);
    $importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    /** @var Transaction|null $augment */
    $augment = Transaction::query()->where('counterparty_name', 'LIKE', '%AUGMENT%')->first();

    expect($augment)->not->toBeNull();
    /** @var Transaction $augment */
    expect($augment->currency)->toBe('USD');
    expect($augment->amount_minor)->toBe(-5000);
    expect($augment->settled_amount_minor)->toBe(-4371);
    expect($augment->settled_currency)->toBe('EUR');
    // The column holds '0.87420000' at scale 8; SQLite trims the trailing
    // zeros back out when reading the stored text.
    expect((string) $augment->fx_rate_used)->toBe('0.8742');
})->group('phase-3');

it('persists rawPayload.format = ics-pdf and a non-empty extractedText per row', function (): void {
    $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    /** @var list<Transaction> $rows */
    $rows = Transaction::query()->get()->all();

    expect($rows)->not->toBe([]);
    foreach ($rows as $row) {
        $payload = $row->raw_payload;
        expect($payload)->toBeArray();
        /** @var array<string, mixed> $payload */
        expect($payload['format'] ?? null)->toBe('ics-pdf');
        $extractedText = $payload['extractedText'] ?? null;
        expect($extractedText)->toBeString();
        expect($extractedText)->not->toBe('');
    }
})->group('phase-3');

it('never persists card-number text into transactions.raw_payload', function (): void {
    $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    foreach (Transaction::all() as $row) {
        $payload = $row->raw_payload ?? [];
        /** @var array<string, mixed> $payload */
        $extracted = $payload['extractedText'] ?? '';
        expect($extracted)->toBeString();
        /** @var string $extracted */
        expect((bool) preg_match('/\*{4}-\*{4}-\*{4}-/u', $extracted))->toBeFalse();
        expect((bool) preg_match('/\d{12,}/', $extracted))->toBeFalse();
    }
})->group('phase-3');

it('prompts the user to name the ICS Account on the first ICS upload', function (): void {
    // seedFixtureUserAndAccount() ships an ICS account; the naming branch only
    // fires when the row is absent.
    Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'ics_card')
        ->delete();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->tinyPdf,
        'ics-pdf',
        $this->fixtureUser,
        'ics-sample-tiny.pdf',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('Name your ICS card account.', false)
        ->assertSee("first time you've imported ICS data", false)
        ->assertSee('Save name', false)
        // Confirm always renders in the page header; the gate is the `disabled`
        // attribute plus the server-side guard in PreviewWizard::confirm().
        ->assertSee('Confirm import', false)
        ->assertSeeHtmlInOrder(['wire:click="confirm"', 'disabled', 'Confirm import']);
})->group('phase-3');

it('skips the name-your-account step on subsequent ICS uploads', function (): void {
    expect(
        Account::query()
            ->where('user_id', $this->fixtureUser->id)
            ->where('kind', 'ics_card')
            ->exists()
    )->toBeTrue();

    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        $this->tinyPdf,
        'ics-pdf',
        $this->fixtureUser,
        'ics-sample-tiny.pdf',
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertDontSee('Name your ICS card account.', false)
        ->assertDontSee("first time you've imported ICS data", false)
        ->assertSee('Confirm import', false);
})->group('phase-3');

it('names an image-only PDF for what it is instead of blaming the header row', function (): void {
    // The reason has to survive to the screen, or the reader is sent to check a
    // header row that was never read. It also has to be the RIGHT reason: this
    // file has no words on it, and no program anyone installs would change that.
    $content = "0 0 1 rg 10 10 120 120 re f\n";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($content).' >>'."\nstream\n".$content.'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number." 0 obj\n".$body."\nendobj\n";
    }
    $startxref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach (array_keys($objects) as $number) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$startxref."\n%%EOF\n";

    $scan = tempnam(sys_get_temp_dir(), 'ics-scan').'.pdf';
    file_put_contents($scan, $pdf);

    try {
        /** @var RunsImports $importer */
        $importer = $this->app->make(RunsImports::class);

        $preview = $importer->runFromUpload($scan, 'ics-pdf', $this->fixtureUser, basename($scan));

        expect($preview->fileFailureReason)->toBe(ImportFailureReason::PdfHasNoTextLayer);

        $html = (string) preg_replace('/\s+/', ' ', Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])->html());

        expect($html)->toContain('scan or a photo of a statement');
        expect($html)->not->toContain('header row that does not match the source you chose');
        expect($html)->not->toContain('pdftotext');
        // The file was refused before a row existed, so the sentence under the
        // heading has to say file. Sharing the row wording told the reader one
        // row could not be read, of a file whose rows were never reached.
        expect($html)->toContain('could not read this file');
    } finally {
        @unlink($scan);
    }
})->group('phase-3');
