<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

// Dropping the wrong PayPal export (Saldorapport instead of Rapport
// Transactiegegevens) must not advance the wizard: ImportPipeline turns the
// typed parse exception into one error row rather than raising, so the step
// has to detect the all-error preview itself.

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
    // Real first lines of a PayPal Saldorapport export. The leading `RH`
    // (Report Header) discriminator is what trips
    // UnsupportedPaypalCsvShapeException inside HeaderSniffer.
    $brrContents = '"RH","Naam rapport","Status rapport","Begindatum en -tijd van rapport","Einddatum en -tijd van rapport","Datum en tijd voor het genereren van rapporten","Hiërarchie","Tijdzone"'."\n"
        .'"RH","BALANCE_RECONCILIATION_REPORT","Success","2026/04/01 00:00:00 +0200","2026/05/12 14:00:00 +0200","2026/05/12 18:17:08 +0200","ABC123","Europe/Berlin"'."\n"
        .'"RD","col1","col2"'."\n"
        .'"RF","Bestandsnummer","Totaal aantal records","Totaal aantal bestanden"'."\n"
        .'"RF","1","0","1"'."\n";

    $csv = UploadedFile::fake()->createWithContent('paypal-balance-reconciliation.csv', $brrContents);

    $component = Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $csv)
        ->call('submit');

    $component->assertNotDispatched('wizard.step.completed');

    // The band must name the right export, not say "could not read this file".
    $component->assertSet('uploadError', fn (?string $value) => $value !== null && str_contains($value, 'Rapport Transactiegegevens'));

    // A stashed id here would hand FirstImportStep the poisoned cache.
    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->first();
    expect($row)->not->toBeNull();
    $stashed = $row->data['paypal_import_run_id'] ?? null;
    expect($stashed)->toBeNull();
});
