<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

/*
 * WR-08 iter-2: OAuthClientWizardModal::submit must catch
 * SecretsWriteFailed and surface an inline errorMessage rather than
 * letting the exception bubble to Livewire's generic "Server error"
 * toast. Without the catch, the user retypes the entire six-step
 * wizard preamble + re-pastes both credentials with no explanation
 * of why their submission failed.
 */

it('surfaces an inline errorMessage on SecretsWriteFailed instead of bubbling the exception', function (): void {
    $user = User::query()->create([
        'username' => 'wizard-disk-fail',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    // Stub the repository to throw on saveProviderClient. Mirrors
    // the EACCES / QueryException production failure mode.
    $db = $this->app->make(DatabaseManager::class);
    $currentUser = $this->app->make(CurrentUser::class);
    $shield = $this->app->make(SecretShield::class);
    $throwingRepo = new class($db, $currentUser, $shield) extends OAuthSecretsRepository
    {
        public function __construct(DatabaseManager $db, CurrentUser $currentUser, SecretShield $shield)
        {
            parent::__construct($db, $currentUser, $shield);
        }

        public function saveProviderClient(
            string $provider,
            string $clientId,
            string $clientSecret,
            string $redirectUri,
        ): void {
            throw new SecretsWriteFailed('fixture: write failed');
        }
    };
    $this->app->instance(OAuthSecretsRepository::class, $throwingRepo);

    Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'GOCSPX-secret-value')
        ->set('publishedConfirmed', true)
        ->call('submit')
        // Inline error surfaces with actionable copy.
        ->assertSet('errorMessage', 'Could not save your OAuth client to disk — check your secrets-directory permissions and try again.')
        // No exception bubbled — the component is still mounted.
        ->assertSet('provider', 'gmail')
        // Secret was wiped per the security posture (intentional —
        // the user must re-paste rather than round-trip the secret
        // through the wire payload on the next render).
        ->assertSet('clientId', '')
        ->assertSet('clientSecret', '');
});

it('still bubbles non-SecretsWriteFailed exceptions (defensive — the catch is narrow)', function (): void {
    $user = User::query()->create([
        'username' => 'wizard-other-fail',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $db = $this->app->make(DatabaseManager::class);
    $currentUser = $this->app->make(CurrentUser::class);
    $shield = $this->app->make(SecretShield::class);
    $throwingRepo = new class($db, $currentUser, $shield) extends OAuthSecretsRepository
    {
        public function __construct(DatabaseManager $db, CurrentUser $currentUser, SecretShield $shield)
        {
            parent::__construct($db, $currentUser, $shield);
        }

        public function saveProviderClient(
            string $provider,
            string $clientId,
            string $clientSecret,
            string $redirectUri,
        ): void {
            throw new RuntimeException('fixture: unexpected non-disk failure');
        }
    };
    $this->app->instance(OAuthSecretsRepository::class, $throwingRepo);

    expect(fn () => Livewire::actingAs($user)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->set('clientId', '123-abc.apps.googleusercontent.com')
        ->set('clientSecret', 'GOCSPX-secret-value')
        ->set('publishedConfirmed', true)
        ->call('submit'))
        ->toThrow(RuntimeException::class, 'fixture: unexpected non-disk failure');
});
