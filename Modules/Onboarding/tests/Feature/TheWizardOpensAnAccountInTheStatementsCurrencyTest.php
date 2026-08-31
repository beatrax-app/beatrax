<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectCardStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Tests\Helpers\UploadIsolation;

// The same defect the preview wizard had, on the three onboarding steps that
// mint an account of their own: the reader's reporting currency was stamped on
// an account whose statement states a different one.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'wizard-denomination',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->upload = function (string $path, string $as): UploadedFile {
        $contents = file_get_contents($path);

        return UploadedFile::fake()->createWithContent($as, $contents === false ? '' : $contents);
    };

    $this->account = fn (string $iban): Account => Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', $iban)
        ->firstOrFail();
});

it('opens the bank account in the currency the uploaded statement states', function (): void {
    $upload = ($this->upload)(base_path('tests/fixtures/asn-camt053-sample-1.xml'), 'statement.xml');

    Livewire::test(ConnectBankStep::class)
        ->set('selectedFormat', 'camt053')
        ->set('file', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    expect(($this->account)('NL57ASNB0123456789')->default_currency)->toBe(Currency::Eur->value);
});

it('opens the ICS card account in euro, which is the only currency ICS bills in', function (): void {
    $upload = ($this->upload)(base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'), 'statement.pdf');

    Livewire::test(ConnectCardStep::class)
        ->set('statements', [$upload])
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    expect(($this->account)('ICS-CARD')->default_currency)->toBe(Currency::Eur->value);
});

it('opens the PayPal wallet in the currency its own export settled in', function (): void {
    $upload = ($this->upload)(base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'), 'activity.csv');

    Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $upload)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    expect(($this->account)('PAYPAL')->default_currency)->toBe(Currency::Eur->value);
});
