<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

/*
 * Regression for the live "0 ROWS · READY" PayPal section produced when
 * the user drops a fatally-shaped CSV on ConnectPaypalStep (e.g. a PayPal
 * Saldorapport / Balance Reconciliation Report instead of the per-event
 * Rapport Transactiegegevens / Transaction Details Report).
 *
 * The shared `ImportPipeline` catches typed parse-time exceptions from
 * adapters / sniffers (UnsupportedPaypalCsvShapeException,
 * UnsupportedPaypalCsvLanguageException, SniffMismatchException) and
 * converts them into a single ERROR-status PreviewRowDto at rowIndex 0
 * so the preview surface can still render with an actionable message
 * instead of 500ing. That contract is correct for the standalone
 * `/imports/upload` flow (the preview screen renders the per-row error
 * message inline), but it leaves ConnectPaypalStep in a corrupt state
 * if the step blindly stashes the import-run id and advances: the
 * FirstImportStep consolidated section reads the cache and renders
 * `READY · 0 ROWS` because no row has status `'new'` / `'enriched'`.
 *
 * The expected behaviour for the wizard step is therefore: when the
 * preview produced ONLY error rows (no committable signal, no unknown-
 * IBAN naming prompt), surface the first error message verbatim on the
 * inline `$uploadError` band — DO NOT stash the import-run id, DO NOT
 * dispatch `wizard.step.completed`. The user fixes the upload (drops
 * the right export) and retries.
 */

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-paypal-fatal-parse',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('surfaces the fatal parse message and does not stash the run id when the user uploads a PayPal Saldorapport by mistake', function (): void {
    // Empirical first lines of the PayPal Saldorapport (Balance
    // Reconciliation Report) export, the wrong-shape CSV the live user
    // dropped on the wizard. The `RH` (Report Header) discriminator
    // triggers UnsupportedPaypalCsvShapeException inside HeaderSniffer,
    // which the ImportPipeline converts into a single ERROR-status
    // PreviewRowDto.
    $brrContents = '"RH","Naam rapport","Status rapport","Begindatum en -tijd van rapport","Einddatum en -tijd van rapport","Datum en tijd voor het genereren van rapporten","Hiërarchie","Tijdzone"'."\n"
        .'"RH","BALANCE_RECONCILIATION_REPORT","Success","2026/04/01 00:00:00 +0200","2026/05/12 14:00:00 +0200","2026/05/12 18:17:08 +0200","ABC123","Europe/Berlin"'."\n"
        .'"RD","col1","col2"'."\n"
        .'"RF","Bestandsnummer","Totaal aantal records","Totaal aantal bestanden"'."\n"
        .'"RF","1","0","1"'."\n";

    $csv = UploadedFile::fake()->createWithContent('paypal-balance-reconciliation.csv', $brrContents);

    $component = Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $csv)
        ->call('submit');

    // The step must NOT advance the wizard — the preview yielded zero
    // committable rows, so there is nothing the FirstImportStep
    // consolidated screen could render usefully.
    $component->assertNotDispatched('wizard.step.completed');

    // The inline error band must carry the actionable hint from the
    // typed exception so the user knows to re-export the right report
    // (not a generic "could not read this file" message).
    $component->assertSet('uploadError', fn (?string $value) => $value !== null && str_contains($value, 'Rapport Transactiegegevens'));

    // wizard_progress.data must NOT carry a paypal_import_run_id —
    // otherwise FirstImportStep would render the section with the
    // poisoned 1-error-row cache.
    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->first();
    expect($row)->not->toBeNull();
    $stashed = $row->data['paypal_import_run_id'] ?? null;
    expect($stashed)->toBeNull();
});
