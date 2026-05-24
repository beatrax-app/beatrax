<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/*
 * /inboxes Connect button wiring.
 *
 *  - openWizard('gmail') / openWizard('microsoft') dispatches the
 *    `oauth-client-wizard:open` event with the chosen provider so the
 *    OAuthClientWizardModal listener picks it up, sets $provider,
 *    re-renders the body branch, and itself dispatches `modal-show`
 *    against the now-correct provider-suffixed name.
 *
 *  - Previously openWizard dispatched `modal-show` directly. The
 *    rendered modal name is computed from $provider, which is null on
 *    first mount, so a `modal-show` targeting `oauth-client-wizard-
 *    microsoft` matched nothing in the DOM — the Connect Microsoft 365
 *    button silently no-op'd. This test locks the corrected handshake.
 *
 *  - openWizard returns a Redirect when the user already has a saved
 *    provider client (skipping the wizard entirely).
 */

function ipowUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('openWizard(gmail) dispatches oauth-client-wizard:open with provider=gmail when no client exists', function (): void {
    $user = ipowUser('wizard-gmail@example.com');

    Livewire::actingAs($user)
        ->test(InboxesPage::class)
        ->call('openWizard', 'gmail')
        ->assertDispatched('oauth-client-wizard:open', provider: 'gmail');
});

it('openWizard(microsoft) dispatches oauth-client-wizard:open with provider=microsoft when no client exists', function (): void {
    $user = ipowUser('wizard-ms@example.com');

    Livewire::actingAs($user)
        ->test(InboxesPage::class)
        ->call('openWizard', 'microsoft')
        ->assertDispatched('oauth-client-wizard:open', provider: 'microsoft');
});

it('openWizard ignores unknown providers and dispatches nothing', function (): void {
    $user = ipowUser('wizard-unknown@example.com');

    Livewire::actingAs($user)
        ->test(InboxesPage::class)
        ->call('openWizard', 'yahoo')
        ->assertNotDispatched('oauth-client-wizard:open')
        ->assertNotDispatched('modal-show');
});

it('OAuthClientWizardModal::open() dispatches modal-show against the provider-suffixed name', function (): void {
    $user = ipowUser('modal-open@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->assertSet('provider', 'gmail')
        ->assertDispatched('modal-show', name: 'oauth-client-wizard-gmail');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'microsoft')
        ->assertSet('provider', 'microsoft')
        ->assertDispatched('modal-show', name: 'oauth-client-wizard-microsoft');
});

it('OAuthClientWizardModal::open() with an invalid provider sets provider to null and dispatches nothing', function (): void {
    $user = ipowUser('modal-invalid@example.com');

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'yahoo')
        ->assertSet('provider', null)
        ->assertNotDispatched('modal-show');
});

it('openWizard redirects to the OAuth connect route when a saved client already exists', function (): void {
    $user = ipowUser('wizard-saved@example.com');

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $this->actingAs($user);
    $secrets->saveProviderClient(
        'gmail',
        '123-abc.apps.googleusercontent.com',
        'GOCSPX-fixture',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );

    Livewire::actingAs($user)
        ->test(InboxesPage::class)
        ->call('openWizard', 'gmail')
        ->assertRedirect(route('oauth.connect', ['provider' => 'gmail']));
});
