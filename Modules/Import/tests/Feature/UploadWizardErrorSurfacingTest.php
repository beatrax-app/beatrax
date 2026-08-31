<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('logs ImportPipeline parse failures via the injected logger when the PayPal language is unsupported', function (): void {
    // The header lacks both language discriminators ("Transactiereferentie",
    // "Reference Txn ID"), so the sniffer takes the PayPal arm and then fails
    // language detection.
    $csv = "Datum,Tijd,Tijdzone,Omschrijving,Valuta\n2026-05-01,10:00:00,Europe/Berlin,Foo,EUR\n";
    $file = UploadedFile::fake()->createWithContent('paypal-unknown-lang.csv', $csv);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', 'paypal-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $ctx): bool {
            return str_starts_with($message, 'ImportPipeline:')
                && ($ctx['source_format'] ?? null) === 'paypal-csv'
                && is_string($ctx['exception_message'] ?? null)
                && str_contains($ctx['exception_message'], 'PayPal CSV');
        })
        ->atLeast()
        ->once();
});

it('logs ImportPipeline parse failures via the injected logger when an ASN CSV header is malformed', function (): void {
    $csv = "totally,unrelated,columns\n1,2,3\n";
    $file = UploadedFile::fake()->createWithContent('not-asn.csv', $csv);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $ctx): bool {
            return str_starts_with($message, 'ImportPipeline:')
                && ($ctx['source_format'] ?? null) === 'asn-csv';
        })
        ->atLeast()
        ->once();
});

it('persists the ImportRun in previewed state even when the pipeline produced only ERROR rows', function (): void {
    // Without the row the redirect target /imports/{id}/preview 404s and the
    // user never sees the ERROR row explaining the failure.
    $csv = "Datum,Tijd,Tijdzone,Omschrijving,Valuta\n2026-05-01,10:00:00,Europe/Berlin,Foo,EUR\n";
    $file = UploadedFile::fake()->createWithContent('paypal-unknown-lang.csv', $csv);

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', 'paypal-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertRedirect();

    expect(ImportRun::query()->where('source_format', 'paypal-csv')->count())->toBe(1);
});

it('leaves uploadError null on the happy path and redirects to the preview screen', function (): void {
    $contents = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'));
    $file = UploadedFile::fake()->createWithContent('paypal-activity.csv', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Csv->value)
        ->set('sourceFormat', 'paypal-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertSet('uploadError', null)
        ->assertRedirect();
});

it('renders the upload-error banner stub when uploadError is set', function (): void {
    // Setting the property directly skips runFromUpload, leaving only the
    // Blade @if branch under test.
    Livewire::test(UploadWizard::class)
        ->set('uploadError', 'Could not process this file (RuntimeException). The full error is in /dev/logs.')
        ->assertSee('data-testid="upload-error-banner"', false)
        ->assertSee('Could not process this file (RuntimeException). The full error is in /dev/logs.', false);
});
