<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

beforeEach(function (): void {
    $this->obwSecretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->obwSecretsPath)) {
        @unlink($this->obwSecretsPath);
    }
    if (is_file($this->obwSecretsPath.'.tmp')) {
        @unlink($this->obwSecretsPath.'.tmp');
    }
});

afterEach(function (): void {
    if (is_file($this->obwSecretsPath)) {
        @unlink($this->obwSecretsPath);
    }
    if (is_file($this->obwSecretsPath.'.tmp')) {
        @unlink($this->obwSecretsPath.'.tmp');
    }
});

function obwUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('open() resets the wizard to step 1 and dispatches modal-show', function (): void {
    $user = obwUser('obw-open');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('open')
        ->assertSet('step', 1)
        ->assertSet('publicKeyPem', '')
        ->assertDispatched('modal-show', name: 'open-banking-wizard');
});

it('generateKeypair() produces a real RSA keypair, reveals only the public key, and never exposes the private PEM on any public property', function (): void {
    $user = obwUser('obw-keypair');

    $testable = Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->assertSet('step', 2);

    $publicKeyPem = $testable->get('publicKeyPem');
    expect($publicKeyPem)->toBeString();
    expect($publicKeyPem)->toContain('BEGIN PUBLIC KEY');

    // From outside the class get_object_vars() returns only PUBLIC properties —
    // exactly the set Livewire serializes into the wire:snapshot sent to the browser.
    $publicProps = get_object_vars($testable->instance());
    foreach ($publicProps as $key => $value) {
        if (is_string($value)) {
            expect($value)
                ->not->toContain('BEGIN PRIVATE KEY')
                ->and($value)->not->toContain('BEGIN RSA PRIVATE KEY');
        }
    }

    // The private key is on disk, just never round-tripped through the component.
    // hasApplication() stays false until step 3 saves a non-empty application_id.
    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    expect($secrets->hasApplication())->toBeFalse();
    $loaded = $secrets->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('');
    expect($loaded->privateKeyPem)->toContain('BEGIN PRIVATE KEY');
});

it('generateKeypair() writes the private key to the secrets file even though hasApplication() stays false until the application_id is saved', function (): void {
    $user = obwUser('obw-partial');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair');

    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    expect($secrets->hasApplication())->toBeFalse();
    expect(is_file($this->obwSecretsPath))->toBeTrue();
});

it('saveApplicationId() persists the application id alongside the already-generated private key', function (): void {
    $user = obwUser('obw-app-id');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->assertSet('step', 4);

    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    expect($secrets->hasApplication())->toBeTrue();

    $loaded = $secrets->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('fixture-application-id');
    expect($loaded->privateKeyPem)->toContain('BEGIN PRIVATE KEY');
});

it('saveApplicationId() rejects an empty application id', function (): void {
    $user = obwUser('obw-app-id-empty');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', '   ')
        ->call('saveApplicationId')
        ->assertSet('step', 3)
        ->assertSet('errorMessage', 'Paste the application ID from the Enable Banking portal before continuing.');
});

it('chooseBank(asn) records the ASN institution id and advances to the consent step', function (): void {
    $user = obwUser('obw-asn');

    Livewire::actingAs($user)
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
    $user = obwUser('obw-other');

    Livewire::actingAs($user)
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
    $user = obwUser('obw-connect');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('continueToApplicationId')
        ->set('applicationId', 'fixture-application-id')
        ->call('saveApplicationId')
        ->call('chooseBank', 'sns')
        ->call('continueToConsent')
        ->call('connect')
        ->assertDispatched('modal-close')
        ->assertRedirect(route('oauth.open-banking.connect', ['institution_id' => 'SNSBNL21']));
});

it('cancel() discards a partially-generated keypair so no orphaned secrets remain', function (): void {
    $user = obwUser('obw-cancel-partial');

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('cancel')
        ->assertDispatched('modal-close');

    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    expect($secrets->hasApplication())->toBeFalse();
    expect($secrets->load())->toBeNull();
    expect(is_file($this->obwSecretsPath))->toBeFalse();
});

it('cancel() leaves a fully-registered application untouched (reconnect flow skipping to Step 4)', function (): void {
    $user = obwUser('obw-cancel-complete');

    /** @var OpenBankingSecretsRepository $secrets */
    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    $secrets->save(new OpenBankingCredentials(
        applicationId: 'existing-application-id',
        privateKeyPem: "-----BEGIN PRIVATE KEY-----\nexisting-fixture\n-----END PRIVATE KEY-----",
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: null,
    ));

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('chooseBank', 'asn')
        ->call('cancel');

    expect($secrets->hasApplication())->toBeTrue();
    $loaded = $secrets->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->applicationId)->toBe('existing-application-id');
});

// Abandoning the wizard after the warning but before consent would otherwise
// leave a live enable authorization in the session, past the warning gate.
it('cancel() forgets the session open-banking-acknowledged flag, regardless of registration state', function (): void {
    $user = obwUser('obw-cancel-forgets-ack');

    session(['open_banking_acknowledged' => now()->getTimestamp()]);

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('generateKeypair')
        ->call('cancel');

    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('cancel() forgets the session ack flag even when a fully-registered application is left untouched', function (): void {
    $user = obwUser('obw-cancel-forgets-ack-registered');

    /** @var OpenBankingSecretsRepository $secrets */
    $secrets = $this->app->make(OpenBankingSecretsRepository::class);
    $secrets->save(new OpenBankingCredentials(
        applicationId: 'existing-application-id',
        privateKeyPem: "-----BEGIN PRIVATE KEY-----\nexisting-fixture\n-----END PRIVATE KEY-----",
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: null,
    ));
    session(['open_banking_acknowledged' => now()->getTimestamp()]);

    Livewire::actingAs($user)
        ->test(OpenBankingWizardModal::class)
        ->call('chooseBank', 'asn')
        ->call('cancel');

    expect(session('open_banking_acknowledged'))->toBeNull();
    expect($secrets->hasApplication())->toBeTrue();
});

it('has no constructor — service collaborators arrive only as action-method parameters (Livewire strict-rules requirement)', function (): void {
    $reflection = new ReflectionClass(OpenBankingWizardModal::class);
    expect($reflection->getConstructor())->toBeNull();
});
