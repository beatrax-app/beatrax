<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// This modal has never had a scanner, on any platform, while the button that
// leads into it offered to scan. On a phone the offer can be made true — there
// is a camera-first pairing screen — so the phone is sent there instead of to a
// text field. On the desktop the offer was simply withdrawn.

function codeEntryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('code-entry-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('sends a phone to the camera-first pairing screen instead of a text field', function (): void {
    $this->actingAs(codeEntryUser('code-entry-phone'));

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        Livewire::test(PairingFlowModal::class)
            ->call('enterACode')
            ->assertRedirect(route('mobile.pair'));
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }
});

it('opens the text field on the desktop, which has no camera path at all', function (): void {
    $this->actingAs(codeEntryUser('code-entry-desktop'));

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->assertSet('step', 'enter_code')
        ->assertNoRedirect();
});

it('no longer offers to scan on a surface that cannot', function (): void {
    expect(trans('sync::pairing.enter_a_code_help', [], 'en'))
        ->not->toContain('scan');
});

// A refused confirmation used to return null and change nothing, which the
// screen rendered as "waiting for the other device" with the confirm button
// disabled. A responder rebinding the row could stall the ceremony for ever
// without anyone seeing why.
it('says so when the keys changed under the words being compared', function (): void {
    $user = codeEntryUser('code-entry-refused');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, app(Session::class));

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');

    // Words that were never derived from this row: the same observable a
    // rebind produces, without needing a second device to produce it.
    $component
        ->set('safetyWords', ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot'])
        ->set('awaitingPeer', true)
        ->call('confirmMatch');

    expect($component->get('flashMessage'))->toBe(Lang::get('sync::pairing.safety_number_changed'));
    expect($component->get('awaitingPeer'))->toBeFalse();
});
