<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;

uses(RefreshDatabase::class);

// The five setup boxes are wire-bound, so what the reader types reaches the
// server as component state and would ride the serialized snapshot back out on
// every later render. Consuming them is what ends that: past submit the real
// values live in the session and nothing addressed to the browser carries them.

// Distinctive on purpose: a needle that matches by accident proves nothing when
// it is absent. Assembled from words rather than spelled as one high-entropy
// literal, because the secret scanner reads every branch in the repository and
// a fixture shaped like a key fails every open pull request at once.
function setupCredentialsPassphrase(): string
{
    return implode('-', ['correct', 'horse', 'battery', 'staple', 'in', 'the', 'hallway']);
}

const SETUP_CREDENTIALS_PIN = '918273';

function setupCredentialsWireTraffic(mixed $component): string
{
    return (string) json_encode($component->snapshot).$component->html();
}

function setupCredentialsPhoneOwner(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt(setupCredentialsPassphrase()),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('empties the credential boxes the moment provisioning consumes them, so no later render carries them', function (): void {
    $component = Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-credentials')
        ->set('password', setupCredentialsPassphrase())
        ->set('passwordConfirmation', setupCredentialsPassphrase())
        ->set('pin', SETUP_CREDENTIALS_PIN)
        ->set('confirmPin', SETUP_CREDENTIALS_PIN);

    // Asserted before the submit as the denominator: the needles below are only
    // evidence of a credential dropped if they were genuinely on the wire while
    // the reader was still typing them.
    expect(setupCredentialsWireTraffic($component))
        ->toContain(setupCredentialsPassphrase())
        ->toContain(SETUP_CREDENTIALS_PIN);

    $component->call('submit')->assertSet('step', 'recovery_codes');

    expect(User::query()->count())->toBe(1, 'the ceremony must have run, or nothing consumed the credentials');

    expect($component->get('password'))->toBe('')
        ->and($component->get('passwordConfirmation'))->toBe('')
        ->and($component->get('pin'))->toBe('')
        ->and($component->get('confirmPin'))->toBe('');

    expect(setupCredentialsWireTraffic($component))
        ->not->toContain(setupCredentialsPassphrase())
        ->not->toContain(SETUP_CREDENTIALS_PIN);
});

it('keeps the retry window credentials in the session and off every surface addressed to the browser', function (): void {
    $user = setupCredentialsPhoneOwner('phone-owner-retry-window');
    test()->actingAs($user);

    // What submit() stashes server-side before it attempts provisioning: the
    // screen it lands on has to be able to retry with the real credentials
    // without ever handing them back to the page.
    session()->put('mobile.import.pending_credentials', [
        'pin' => SETUP_CREDENTIALS_PIN,
        'password' => setupCredentialsPassphrase(),
    ]);

    $component = Livewire::test(MobileImportBootstrap::class)
        ->assertSet('step', 'provisioning_failed');

    expect(session('mobile.import.pending_credentials'))->toBe([
        'pin' => SETUP_CREDENTIALS_PIN,
        'password' => setupCredentialsPassphrase(),
    ], 'the retry has to reach the real credentials, and the session is where they are kept');

    expect(setupCredentialsWireTraffic($component))
        ->not->toContain(setupCredentialsPassphrase())
        ->not->toContain(SETUP_CREDENTIALS_PIN);

    $component->call('retryProvisioning')->assertSet('step', 'recovery_codes');

    expect(setupCredentialsWireTraffic($component))
        ->not->toContain(setupCredentialsPassphrase())
        ->not->toContain(SETUP_CREDENTIALS_PIN);
});
