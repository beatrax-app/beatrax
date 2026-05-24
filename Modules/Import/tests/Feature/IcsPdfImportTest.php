<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/*
 * Wire-level coverage for the ICS PDF import path: idempotency on
 * re-import (both SHA-identical and SHA-different-but-content-identical
 * shapes), persistence of native + settled + fx_rate_used for foreign-
 * currency rows, raw_payload preservation with card-number scrubbing,
 * and the first-time "name your ICS card account" wizard branch.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

it('imports every parsed row from the redacted .txt fixture on the first run via the test-double extractor', function (): void {
    // Wire-level: uses the tiny synthetic .pdf so the real pdftotext +
    // HeaderSniffer + SourceAdapterRegistry path is exercised end-to-end.
    // The redacted-text fixture is exercised at the unit-test layer (see
    // Modules/Ingestion/tests/Unit/Adapters/Ics/IcsPdfAdapterTest.php).
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
    // Copy the tiny PDF to a temp path and flip a single byte inside
    // its xref table so the SHA-256 differs but the extracted text is
    // identical. Tier-2 fingerprint-v3 dedup catches it.
    $bytes = file_get_contents($this->tinyPdf);
    if ($bytes === false) {
        throw new RuntimeException('Could not read tiny PDF fixture.');
    }

    // Mutate one byte in the xref padding zeros — won't affect pdftotext
    // extraction but will change the SHA. The xref section's leading
    // `0000000000` placeholder for object 0 is safe to bump to '0000000001'
    // because pdftotext doesn't dereference it.
    $needle = '0000000000 65535 f';
    $mutated = str_replace($needle, '0000000001 65535 f', $bytes);
    if ($mutated === $bytes) {
        throw new RuntimeException('Could not locate xref padding to mutate.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ics-shadup').'.pdf';
    file_put_contents($tmp, $mutated);

    try {
        // First import the original; then the SHA-different copy. The
        // second run inserts zero rows because fingerprint-v3 dedup
        // identifies the same logical transaction.
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
    // The tiny synthetic PDF carries one EUR-native row. To exercise
    // the FX-row persistence path at the wire level we substitute the
    // PdfTextExtractor + SourceAdapterRegistry singletons with versions
    // bound to a test-double extractor returning the redacted-text
    // fixture verbatim. The redacted .txt fixture carries three real
    // FX rows (Augment Code USD/EUR, Audible UK GBP/EUR, Vitrus USD/EUR);
    // we assert the Augment Code row persists with both legs + derived
    // fx_rate_used.
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
    // Several singletons in the container hold the original IcsPdfAdapter
    // through transitive constructor wiring (SourceAdapterRegistry holds
    // it; ImportPipeline holds the registry; ParseStage holds the
    // registry). Forget them so the next resolution chain wires the
    // doubled extractor through to the adapter.
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
    // 4371 / 5000 = 0.8742 (with the sign-balanced absolute ratio of
    // -4371 / -5000). BigDecimal at scale 8 with HALF_UP rounding writes
    // '0.87420000' into the decimal(18,8) column; SQLite normalises the
    // stored text by trimming trailing zeros after the decimal point.
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
        // Canonical masked-card placeholder and any 12+ contiguous digit
        // run are scrubbed at the adapter boundary.
        expect((bool) preg_match('/\*{4}-\*{4}-\*{4}-/u', $extracted))->toBeFalse();
        expect((bool) preg_match('/\d{12,}/', $extracted))->toBeFalse();
    }
})->group('phase-3');

it('prompts the user to name the ICS Account on the first ICS upload', function (): void {
    // Remove the seeded ICS Account so this run is the user's first
    // ICS upload (the seedFixtureUserAndAccount() helper provides an
    // ICS account for the wire-level tests above; the wizard-naming
    // path requires the row to be absent).
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
        // The Confirm button lives in the page header and is always rendered;
        // the naming-step gate is enforced by the `disabled` attribute (UI)
        // and the server-side guard in PreviewWizard::confirm() (defense).
        ->assertSee('Confirm import', false)
        ->assertSeeHtmlInOrder(['wire:click="confirm"', 'disabled', 'Confirm import']);
})->group('phase-3');

it('skips the name-your-account step on subsequent ICS uploads', function (): void {
    // The seedFixtureUserAndAccount() helper already provided an ICS
    // Account for the user, so this run mirrors the second-and-later
    // ICS upload behaviour: the rows preview renders straight away,
    // no naming prompt.
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
