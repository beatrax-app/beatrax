<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;

uses(RefreshDatabase::class);

// Three of the five setup boxes are wire-bound, so what the reader types reaches
// the server as component state and would ride the serialized snapshot back out
// on every later render. Consuming them is what ends that: past submit the real
// values live in the session and nothing addressed to the browser carries them.
// The two code boxes are not among them and never were -- see the last case.

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
        ->set('passwordConfirmation', setupCredentialsPassphrase());

    // Asserted before the submit as the denominator: the passphrase below is
    // only evidence of a credential dropped if it was genuinely on the wire
    // while the reader was still typing it. The code has no such denominator
    // here -- it never reaches a property, so it is never on the wire at all.
    expect(setupCredentialsWireTraffic($component))
        ->toContain(setupCredentialsPassphrase())
        ->not->toContain(SETUP_CREDENTIALS_PIN);

    $component->call('submit', SETUP_CREDENTIALS_PIN, SETUP_CREDENTIALS_PIN)
        ->assertSet('step', 'recovery_codes');

    expect(User::query()->count())->toBe(1, 'the ceremony must have run, or nothing consumed the credentials');

    expect($component->get('password'))->toBe('')
        ->and($component->get('passwordConfirmation'))->toBe('');

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

// The branch the two above never walk. reportBrokenFieldRules() returns above
// every line that empties a box, so on a rejected submit the snapshot is
// rendered with whatever the properties still hold -- and a mistyped confirm
// box on a five-field phone form is the everyday case, not the exotic one.
it('keeps the code off the wire on a rejected submit, which is the render that carries the most', function (): void {
    $component = Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-rejected')
        ->set('password', setupCredentialsPassphrase())
        ->set('passwordConfirmation', setupCredentialsPassphrase())
        ->call('submit', SETUP_CREDENTIALS_PIN, '000000');

    $component->assertHasErrors('confirmPin')
        ->assertSet('step', 'collect_pin');

    expect(User::query()->count())->toBe(0, 'a rejected submit must not have created the account');

    // The positive control for this render: the passphrase IS here, on the
    // argument the allow-list makes for a password and not for a code. Without
    // it, an absent code proves only that the needle was unfindable.
    expect(setupCredentialsWireTraffic($component))
        ->toContain(setupCredentialsPassphrase())
        ->not->toContain(SETUP_CREDENTIALS_PIN);
});
