<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renders the calm upload form on GET /imports/new', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Upload statement', false);
});

it('requires a source format declaration', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', '')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('rejects an unsupported source format with the in:asn-csv rule', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'camt-053')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('rejects a file larger than 10 MB with the locked oversized copy', function (): void {
    $oversized = UploadedFile::fake()->create('big.csv', 10_241);

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $oversized)
        ->call('submit')
        ->assertHasErrors(['file']);
});

it('rejects a non-CSV upload with the bad-MIME copy from the sniffer', function (): void {
    // .exe extension fails the Livewire mimes rule (which now admits
    // csv,txt,xml,sta,mt940,940,pdf). The .pdf extension is admitted at
    // the validator layer now that ICS PDF imports are supported; the
    // content-vs-declared-format mismatch for a .pdf-with-asn-csv-format
    // upload is caught downstream at the HeaderSniffer boundary.
    $badMime = UploadedFile::fake()->create('not-a-csv.exe', 5);

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $badMime)
        ->call('submit')
        ->assertHasErrors(['file']);
});

it('redirects to the preview page after a successful upload', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../../tests/fixtures/asn-sample-1.csv');
    $file = UploadedFile::fake()->createWithContent('asn-statement.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertRedirect();

    expect(ImportRun::count())->toBe(1);
    expect(ImportRun::query()->first()?->status)->toBe('previewed');
});

it('opens the wizard with ASN as the default issuer and asn-csv as the default format', function (): void {
    Livewire::test(UploadWizard::class)
        ->assertSet('issuer', 'asn')
        ->assertSet('sourceFormat', 'asn-csv');
})->group('phase-3');

it('returns only the PDF format when the issuer is ICS', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('issuer', 'ics');

    /** @var UploadWizard $instance */
    $instance = $component->instance();
    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => 'ics-pdf', 'label' => 'PDF'],
    ]);
})->group('phase-3');

it('returns the three ASN formats when the issuer is ASN', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('issuer', 'asn');

    /** @var UploadWizard $instance */
    $instance = $component->instance();
    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => 'asn-csv', 'label' => 'CSV'],
        ['value' => 'camt053', 'label' => 'CAMT.053 (XML)'],
        ['value' => 'mt940', 'label' => 'MT940'],
    ]);
})->group('phase-3');

it('resets sourceFormat to the first leaf when the issuer changes', function (): void {
    Livewire::test(UploadWizard::class)
        ->set('issuer', 'ics')
        ->assertSet('sourceFormat', 'ics-pdf')
        ->set('issuer', 'asn')
        ->assertSet('sourceFormat', 'asn-csv');
})->group('phase-3');

it('lets the user pick ICS issuer and ics-pdf format and submit', function (): void {
    $pdfPath = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
    $contents = file_get_contents($pdfPath);
    $file = UploadedFile::fake()->createWithContent('ics-statement.pdf', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'ics')
        ->set('sourceFormat', 'ics-pdf')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(ImportRun::query()->where('source_format', 'ics-pdf')->count())->toBe(1);
})->group('phase-3');

it('renders the two-step picker on the upload page', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Source', false);
    $response->assertSee('Format', false);
    $response->assertSee('Drop in a bank, card, or PayPal export, or an email receipt file.', false);
    $response->assertSee('wire:model.live="issuer"', false);
})->group('phase-3');
