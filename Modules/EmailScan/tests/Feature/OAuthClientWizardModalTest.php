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

function ocwUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('open() sets the provider to gmail', function (): void {
    $user = ocwUser('open@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->assertSet('provider', 'gmail');
});

it('open(gmail) dispatches modal-show for oauth-client-wizard-gmail so the Flux modal actually opens', function (): void {
    $user = ocwUser('modal-show-gmail@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->assertDispatched('modal-show', name: 'oauth-client-wizard-gmail');
});

it('open(microsoft) dispatches modal-show for oauth-client-wizard-microsoft so the Flux modal actually opens', function (): void {
    $user = ocwUser('modal-show-ms@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->assertSet('provider', 'microsoft')
        ->assertDispatched('modal-show', name: 'oauth-client-wizard-microsoft');
});

it('open() does not dispatch modal-show for an unknown provider', function (): void {
    $user = ocwUser('modal-show-unknown@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'unknown-provider')
        ->assertSet('provider', null)
        ->assertNotDispatched('modal-show');
});

it('rejects an invalid client_id with the locked error copy', function (): void {
    $user = ocwUser('bad-id@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', 'not-google-shaped')
        ->set('clientSecret', 'GOCSPX-anything')
        ->set('publishedConfirmed', true)
        ->call('submit')
        ->assertSet('errorMessage', 'Enter a Google OAuth client ID ending in .apps.googleusercontent.com.');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('gmail'))->toBeFalse();
});

it('rejects an invalid client_secret with the locked error copy', function (): void {
    $user = ocwUser('bad-secret@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'wrong-prefix')
        ->set('publishedConfirmed', true)
        ->call('submit')
        ->assertSet('errorMessage', 'Enter a Google OAuth client secret starting with GOCSPX-.');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('gmail'))->toBeFalse();
});

it('rejects submit when publishedConfirmed is false', function (): void {
    $user = ocwUser('unpublished@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'GOCSPX-something')
        ->set('publishedConfirmed', false)
        ->call('submit')
        ->assertSet('errorMessage', "Confirm that you've pushed your OAuth consent screen to 'In production'.");

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('gmail'))->toBeFalse();
});

it('happy path: writes the provider client via OAuthSecretsRepository + dispatches modal-hide + redirects to /oauth/connect/gmail', function (): void {
    $user = ocwUser('happy@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'GOCSPX-secret-value')
        ->set('publishedConfirmed', true)
        ->call('submit')
        ->assertDispatched('modal-close')
        ->assertRedirect(route('oauth.connect', ['provider' => 'gmail']));

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    expect($secrets->hasProviderClient('gmail'))->toBeTrue();

    $loaded = $secrets->loadProviderClient('gmail');
    expect($loaded)->not->toBeNull();
    expect($loaded['client_id'])->toBe('123-abc.apps.googleusercontent.com');
    expect($loaded['client_secret'])->toBe('GOCSPX-secret-value');
});

it('successful submit wipes clientId + clientSecret from the component instance so the snapshot never carries them', function (): void {
    $user = ocwUser('wipe@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'GOCSPX-secret-value')
        ->set('publishedConfirmed', true)
        ->call('submit')
        ->assertSet('clientId', '')
        ->assertSet('clientSecret', '');
});
