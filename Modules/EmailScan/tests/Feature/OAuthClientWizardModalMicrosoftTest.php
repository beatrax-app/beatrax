<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

function ocwmUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('open(microsoft) sets the provider to microsoft', function (): void {
    $user = ocwmUser('open-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->assertSet('provider', 'microsoft');
});

it('renders the six Azure-specific numbered steps when provider is microsoft', function (): void {
    $user = ocwmUser('render-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->assertSee('Open Azure Portal')
        ->assertSee('Register a new application')
        ->assertSee('Add the redirect URI')
        ->assertSee('Grant Mail.Read permission')
        ->assertSee('Create a client secret')
        ->assertSee('Paste your application (client) ID and secret')
        ->assertSee('Set up your Microsoft 365 OAuth client');
});

it('rejects a non-UUID client_id with the locked error copy', function (): void {
    $user = ocwmUser('bad-id-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->set('clientId', 'not-a-uuid')
        ->set('clientSecret', 'whatever-secret')
        ->call('submit')
        ->assertSet('errorMessage', 'Enter the application (client) ID — a UUID like 12345678-1234-1234-1234-123456789abc.');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('microsoft'))->toBeFalse();
});

it('rejects a UUID-shaped but non-v4 client_id with the locked error copy', function (): void {
    $user = ocwmUser('bad-uuid-ms@example.com');

    // Third group must start with 1-5 (RFC 4122 version nibble); a leading 7
    // means the value is well-formed UUID hex but the wrong version.
    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->set('clientId', '11111111-2222-7333-89ab-444444444444')
        ->set('clientSecret', 'whatever-secret')
        ->call('submit')
        ->assertSet('errorMessage', 'Enter the application (client) ID — a UUID like 12345678-1234-1234-1234-123456789abc.');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('microsoft'))->toBeFalse();
});

it('rejects an empty client_secret with the locked error copy', function (): void {
    $user = ocwmUser('empty-secret-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->set('clientId', '12345678-1234-4abc-89ab-123456789abc')
        ->set('clientSecret', '')
        ->call('submit')
        ->assertSet('errorMessage', 'Enter the client secret value Azure showed you when you created the secret.');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('microsoft'))->toBeFalse();
});

it('happy path: writes the provider client via OAuthSecretsRepository + dispatches modal-hide + redirects to /oauth/connect/microsoft', function (): void {
    $user = ocwmUser('happy-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->set('clientId', '12345678-1234-4abc-89ab-123456789abc')
        ->set('clientSecret', 'real-secret-value')
        ->call('submit')
        ->assertDispatched('modal-close')
        ->assertRedirect(route('oauth.connect', ['provider' => 'microsoft']));

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('microsoft'))->toBeTrue();

    $loaded = $secrets->loadProviderClient('microsoft');
    expect($loaded)->not->toBeNull();
    expect($loaded['client_id'])->toBe('12345678-1234-4abc-89ab-123456789abc');
    expect($loaded['client_secret'])->toBe('real-secret-value');
});

it('Microsoft submit succeeds with publishedConfirmed=false — the checkbox does not gate the Microsoft variant', function (): void {
    $user = ocwmUser('no-published-ms@example.com');

    // publishedConfirmed is a Google-only gate — Azure has no "push to
    // production" step — and the Microsoft UI never shows the checkbox.
    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->set('clientId', 'abcdef01-2345-4678-89ab-cdef01234567')
        ->set('clientSecret', 'another-secret')
        ->set('publishedConfirmed', false)
        ->call('submit')
        ->assertSet('errorMessage', '')
        ->assertRedirect(route('oauth.connect', ['provider' => 'microsoft']));

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('microsoft'))->toBeTrue();
});
