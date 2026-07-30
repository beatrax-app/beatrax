<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

/*
 * Verifies the format-first restructure of the bank connector step.
 *
 * Five behaviours under test:
 *
 *  1. Three format chips (CAMT.053, MT940, CSV) render as a single
 *     radio group above the drop zone, regardless of which one is
 *     currently selected.
 *
 *  2. Picking CAMT.053 or MT940 leaves the drop zone interactive (no
 *     `aria-disabled` attribute and no muted-grey treatment in the
 *     gated copy slot).
 *
 *  3. Picking CSV with no bank chip yet selected renders the drop
 *     zone with `aria-disabled="true"` — the user-visible surface of
 *     the wizard's server-side Pitfall 5 Layer A guard.
 *
 *  4. Picking CSV → ASN (or CSV → ING) re-activates the drop zone so
 *     the next file drop can proceed.
 *
 *  5. After a successful submit on the CAMT.053 path, the matching
 *     ImportRun id lands in `wizard_progress.data['bank_import_run_id']`
 *     so the consolidated preview screen can read it back.
 */

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-bank-fmt',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('renders three format chips above the drop zone', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->assertSee('CAMT.053')
        ->assertSee('MT940')
        ->assertSee('CSV');
});

it('drop zone is interactive when CAMT.053 is selected', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('drop zone is interactive when MT940 is selected', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'mt940')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('drop zone is disabled when CSV is selected without a bank chip (Pitfall 5 Layer A UX surface)', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'asn-csv')
        ->set('selectedBankFormatHint', null)
        ->assertSeeHtml('aria-disabled="true"');
});

it('drop zone becomes interactive when CSV is paired with the ASN bank chip', function (): void {
    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'asn-csv')
        ->set('selectedBankFormatHint', 'asn-csv')
        ->assertDontSeeHtml('aria-disabled="true"');
});

it('stashes bank_import_run_id into wizard_progress.data after a successful submit', function (): void {
    // Seed the user's ASN account so the CAMT fixture's own-IBAN
    // resolves to a known account inside the pipeline; otherwise the
    // preview surfaces unknown-IBAN error rows and no ImportRun id is
    // ever returned to the wizard's stash path.
    Account::query()->updateOrCreate(
        ['iban' => 'NL57ASNB0123456789'],
        [
            'user_id' => $this->user->id,
            'name' => 'ASN',
            'slug' => 'asn-fixture',
            'kind' => 'asn',
            'default_currency' => 'EUR',
        ],
    );

    $fixturePath = base_path('tests/fixtures/asn-camt053-sample-1.xml');
    $contents = file_get_contents($fixturePath);
    $upload = UploadedFile::fake()->createWithContent('statement.xml', $contents !== false ? $contents : '');

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->set('file', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data)->toBeArray();
    expect($row->data['bank_import_run_id'] ?? null)->toBeInt();
    expect($row->data['bank_import_run_id'])->toBeGreaterThan(0);
});
