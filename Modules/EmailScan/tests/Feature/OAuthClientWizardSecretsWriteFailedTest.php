<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Livewire\Livewire;
use Modules\Core\Models\User;
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

it('surfaces an inline errorMessage on SecretsWriteFailed instead of bubbling the exception', function (): void {
    $user = User::query()->create([
        'username' => 'wizard-disk-fail',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    // Stub the repository to throw on saveProviderClient. Mirrors
    // the disk-full / EACCES production failure mode.
    $files = $this->app->make(Filesystem::class);
    $throwingRepo = new class($files) extends OAuthSecretsRepository
    {
        public function __construct(Filesystem $files)
        {
            parent::__construct($files);
        }

        public function saveProviderClient(
            string $provider,
            string $clientId,
            string $clientSecret,
            string $redirectUri,
        ): void {
            throw new SecretsWriteFailed('fixture: disk full');
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
        ->assertSet('errorMessage', 'Could not save your OAuth client to disk — check storage/app/secrets/ permissions and try again.')
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

    $files = $this->app->make(Filesystem::class);
    $throwingRepo = new class($files) extends OAuthSecretsRepository
    {
        public function __construct(Filesystem $files)
        {
            parent::__construct($files);
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
