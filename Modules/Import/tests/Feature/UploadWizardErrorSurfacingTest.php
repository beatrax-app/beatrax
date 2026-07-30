<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

/*
 * UploadWizard parse-time error surfacing + log capture.
 *
 *  - When the import pipeline raises a parse failure (sniff mismatch,
 *    unsupported PayPal CSV language, etc.) it now logs the failure
 *    via the injected PSR LoggerInterface so the entry surfaces on
 *    /dev/logs. The pipeline still converts the exception into an
 *    ERROR preview row so the wizard's preview screen renders the
 *    user-facing message; the log entry adds the stack trace + class
 *    that the row body intentionally omits.
 *
 *  - The wizard-layer catch-all in UploadWizard::submit() handles
 *    failures that bubble OUT of runFromUpload — file-system errors,
 *    hash failures, ImportRun insert clashes — that the pipeline's
 *    own try/catch cannot wrap. Those populate `uploadError` so the
 *    wizard renders an inline error banner instead of stranding the
 *    user on a blank Livewire toast.
 *
 *  - The happy path leaves uploadError null and redirects.
 */

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('logs ImportPipeline parse failures via the injected logger when the PayPal language is unsupported', function (): void {
    // PayPal CSV with header row that lacks the discriminator tokens
    // ("Transactiereferentie", "Reference Txn ID") — sniffer routes
    // through the PayPal arm, language detect returns null, sniffer
    // raises UnsupportedPaypalCsvLanguageException which the pipeline
    // catches into an ERROR row.
    $csv = "Datum,Tijd,Tijdzone,Omschrijving,Valuta\n2026-05-01,10:00:00,Europe/Berlin,Foo,EUR\n";
    $file = UploadedFile::fake()->createWithContent('paypal-unknown-lang.csv', $csv);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'paypal')
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
        ->set('issuer', 'asn')
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
    // Confirms the failure case still produces a navigable preview
    // page: the wizard redirects to /imports/{id}/preview where the
    // ERROR row is visible. Without the ImportRun the redirect target
    // would 404.
    $csv = "Datum,Tijd,Tijdzone,Omschrijving,Valuta\n2026-05-01,10:00:00,Europe/Berlin,Foo,EUR\n";
    $file = UploadedFile::fake()->createWithContent('paypal-unknown-lang.csv', $csv);

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'paypal')
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
        ->set('issuer', 'paypal')
        ->set('sourceFormat', 'paypal-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertSet('uploadError', null)
        ->assertRedirect();
});

it('renders the upload-error banner stub when uploadError is set', function (): void {
    // Direct set() bypasses the runFromUpload guard so the test asserts
    // the wizard view actually renders the inline error surface when
    // a wizard-layer failure populates the property. This locks the
    // Blade @if branch's visibility.
    Livewire::test(UploadWizard::class)
        ->set('uploadError', 'Could not process this file (RuntimeException). The full error is in /dev/logs.')
        ->assertSee('data-testid="upload-error-banner"', false)
        ->assertSee('Could not process this file (RuntimeException). The full error is in /dev/logs.', false);
});
