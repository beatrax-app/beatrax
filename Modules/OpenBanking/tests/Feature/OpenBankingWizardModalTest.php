<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

// Every wizard action names the reader it writes for, so each case signs one in
// and reads that reader's own file back. Livewire resolves CurrentUser from the
// container, which is why actingAs() is not optional here.

beforeEach(function (): void {
    $this->obwUser = User::query()->create([
        'username' => 'obw-fixture',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->obwUser);
    $this->obwSecretsPath = OpenBankingSecretsFixture::path($this->obwUser->id);
    OpenBankingSecretsFixture::forget($this->obwUser->id);
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget($this->obwUser->id);
});

function obwSecrets(): OpenBankingSecretsRepository
{
    return OpenBankingSecretsFixture::repository();
}

it('open() resets the wizard to step 1 and dispatches modal-show', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('open')
        ->assertSet('step', 1)
        ->assertSet('publicKeyPem', '')
        ->assertDispatched('modal-show', name: 'open-banking-wizard');
});

it('generateKeypair() produces a real RSA keypair, reveals only the public key, and never exposes the private PEM on any public property', function (): void {
    $testable = Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->assertSet('step', 2);

    $publicKeyPem = $testable->get('publicKeyPem');
    expect($publicKeyPem)->toBeString();
    expect($publicKeyPem)->toContain('BEGIN PUBLIC KEY');

    // From outside the class get_object_vars() returns only PUBLIC properties —
    // exactly the set Livewire serializes into the wire:snapshot sent to the browser.
    $publicProps = get_object_vars($testable->instance());
    foreach ($publicProps as $value) {
        if (is_string($value)) {
            expect($value)
                ->not->toContain('BEGIN PRIVATE KEY')
                ->and($value)->not->toContain('BEGIN RSA PRIVATE KEY');
        }
    }

    // The private key is on disk, just never round-tripped through the component.
    // hasApplication() stays false until step 3 saves a non-empty application_id.
    $secrets = obwSecrets();
    expect($secrets->hasApplication($this->obwUser->id))->toBeFalse();
    $loaded = $secrets->load($this->obwUser->id);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('');
    expect($loaded->privateKeyPem)->toContain('BEGIN PRIVATE KEY');
});

it('generateKeypair() writes the private key into THIS reader\'s file even though hasApplication() stays false until the application_id is saved', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair');

    expect(obwSecrets()->hasApplication($this->obwUser->id))->toBeFalse();
    expect(is_file($this->obwSecretsPath))->toBeTrue();
});

it('saveApplicationId() persists the application id alongside the already-generated private key', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->assertSet('step', 4);

    $secrets = obwSecrets();
    expect($secrets->hasApplication($this->obwUser->id))->toBeTrue();

    $loaded = $secrets->load($this->obwUser->id);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('fixture-application-id');
    expect($loaded->privateKeyPem)->toContain('BEGIN PRIVATE KEY');
});

it('saveApplicationId() rejects an empty application id', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', '   ')
        ->call('saveApplicationId')
        ->assertSet('step', 3)
        ->assertSet('errorMessage', 'Paste the application ID from the Enable Banking portal before continuing.');
});

it('chooseBank(asn) records the ASN institution id and advances to the consent step', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->call('chooseBank', 'asn')
        ->assertSet('bankChoice', 'asn')
        ->call('continueToConsent')
        ->assertSet('step', 5);
});

it('chooseBank(other) requires a non-empty free-text institution id before continuing — no bank is ever hardcoded', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->call('chooseBank', 'other')
        ->call('continueToConsent')
        ->assertSet('step', 4)
        ->assertSet('errorMessage', 'Choose a bank before continuing.')
        ->set('otherInstitutionId', 'RABONL2U')
        ->call('continueToConsent')
        ->assertSet('step', 5);
});

it('connect() redirects to oauth.open-banking.connect with the chosen institution id', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->call('chooseBank', 'sns')
        ->call('continueToConsent')
        ->call('connect')
        ->assertDispatched('modal-close')
        ->assertRedirect(route('oauth.open-banking.connect', [
            'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        ]));
});

it('cancel() discards a partially-generated keypair so no orphaned secrets remain', function (): void {
    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('cancel')
        ->assertDispatched('modal-close');

    $secrets = obwSecrets();
    expect($secrets->hasApplication($this->obwUser->id))->toBeFalse();
    expect($secrets->load($this->obwUser->id))->toBeNull();
    expect(is_file($this->obwSecretsPath))->toBeFalse();
});

it('cancel() leaves a fully-registered application untouched (reconnect flow skipping to Step 4)', function (): void {
    OpenBankingSecretsFixture::seedApplication($this->obwUser->id, 'existing-application-id');

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('chooseBank', 'asn')
        ->call('cancel');

    $secrets = obwSecrets();
    expect($secrets->hasApplication($this->obwUser->id))->toBeTrue();
    $loaded = $secrets->load($this->obwUser->id);
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('existing-application-id');
});

// A wizard abandoned mid-flight must not take a bank that is already linked
// down with it: the cancel path only discards a file with no application in it.
it('cancel() leaves an already-connected bank\'s session in place', function (): void {
    OpenBankingSecretsFixture::seed($this->obwUser->id);

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('chooseBank', 'sns')
        ->call('cancel');

    expect(obwSecrets()->connectedInstitutions($this->obwUser->id))
        ->toBe([OpenBankingSecretsFixture::INSTITUTION_ID]);
});

// Abandoning the wizard after the warning but before consent would otherwise
// leave a live enable authorization in the session, past the warning gate.
it('cancel() forgets the session open-banking-acknowledged flag, regardless of registration state', function (): void {
    session(['open_banking_acknowledged' => now()->getTimestamp()]);

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('cancel');

    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('cancel() forgets the session ack flag even when a fully-registered application is left untouched', function (): void {
    OpenBankingSecretsFixture::seedApplication($this->obwUser->id, 'existing-application-id');
    session(['open_banking_acknowledged' => now()->getTimestamp()]);

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->call('chooseBank', 'asn')
        ->call('cancel');

    expect(session('open_banking_acknowledged'))->toBeNull();
    expect(obwSecrets()->hasApplication($this->obwUser->id))->toBeTrue();
});

it('open() honours a legal start step for an already-registered application', function (): void {
    OpenBankingSecretsFixture::seedApplication($this->obwUser->id, 'existing-application-id');

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->dispatch('open-banking-wizard:open', startStep: 4, bankChoice: 'asn', otherInstitutionId: '')
        ->assertSet('step', 4)
        ->assertSet('bankChoice', 'asn')
        ->assertSee('Choose your bank');
});

// The skip is gated on THIS reader's application, so another reader's
// registration cannot open the picker over an unregistered one.
it('open() sends a reader with no application of their own back to step 1', function (): void {
    $registered = User::query()->create([
        'username' => 'obw-other-reader',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    OpenBankingSecretsFixture::seedApplication($registered->id, 'existing-application-id');

    try {
        Livewire::actingAs($this->obwUser)
            ->test(OpenBankingWizardModal::class)
            ->dispatch('open-banking-wizard:open', startStep: 4, bankChoice: 'asn', otherInstitutionId: '')
            ->assertSet('step', 1)
            ->assertSet('bankChoice', '');
    } finally {
        OpenBankingSecretsFixture::forget($registered->id);
    }
});

// The start step arrives on a client-triggerable event and picks the branch the
// modal renders. An unknown number matched no branch, so the reader got a
// dialog with a heading, no controls, and no way out of it.
it('open() refuses a start step that is not a real step, rather than rendering a modal with no controls', function (): void {
    OpenBankingSecretsFixture::seedApplication($this->obwUser->id, 'existing-application-id');

    Livewire::actingAs($this->obwUser)
        ->test(OpenBankingWizardModal::class)
        ->dispatch('open-banking-wizard:open', startStep: 99, bankChoice: 'asn', otherInstitutionId: '')
        ->assertSet('step', 1)
        ->assertSet('bankChoice', '')
        ->assertSee('Generate your local key pair');
});

it('has no constructor — service collaborators arrive only as action-method parameters (Livewire strict-rules requirement)', function (): void {
    $reflection = new ReflectionClass(OpenBankingWizardModal::class);
    expect($reflection->getConstructor())->toBeNull();
});
