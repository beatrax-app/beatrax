<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/*
 * OAuth-client-registration wizard modal (Google variant).
 *
 * Exercises:
 *  - open() sets the provider.
 *  - submit() validates the client_id ends in .apps.googleusercontent.com,
 *    the secret starts with GOCSPX-, and the publishedConfirmed checkbox
 *    is ticked. Each failure surfaces the locked error copy and the
 *    secrets file is not written.
 *  - The happy path writes via OAuthSecretsRepository, dispatches
 *    modal-hide, and redirects to /oauth/connect/gmail.
 */

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

function ocwUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
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
        ->assertDispatched('modal-hide')
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
