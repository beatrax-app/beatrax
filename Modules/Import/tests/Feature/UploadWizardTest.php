<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renders the calm upload form on GET /imports/new', function (): void {
    $response = $this->get('/imports/new');

    $response->assertOk();
    $response->assertSee('Upload statement', false);
});

it('requires a source format declaration (ING-07)', function (): void {
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
    // .pdf extension fails the 'mimes:csv,txt' Livewire rule
    $badMime = UploadedFile::fake()->create('not-a-csv.pdf', 5);

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
