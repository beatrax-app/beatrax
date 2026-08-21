<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;

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

    // Nothing on the wire, so the ceremony is driven entirely from the rows.
    app()->instance(PeerDiscovery::class, new class implements PeerDiscovery
    {
        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return [];
        }
    });
    Http::fake(['*' => Http::response('', 503)]);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');

    /** @var PairingTokenService $tokenService */
    $tokenService = app(PairingTokenService::class);
    $token = app(WordCodeEncoder::class)->decode($component->get('wordCode'));
    $tokenService->accept($token, (int) $user->id, 'the-honest-phone', str_repeat('c', 64), str_repeat('d', 64));

    // The poll derives the words from the keys bound at that moment. They are
    // never set from the client: what the tap is bound to is the server's
    // decision, which is why the property is locked.
    $component->call('checkPairingState')->assertSet('step', 'confirm');

    // A second responder takes the slot nobody has confirmed, so the keys
    // behind the words on screen are no longer the keys the row holds.
    $tokenService->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        '99999999-8888-4777-8666-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
    );

    $component->call('confirmMatch');

    expect($component->get('flashMessage'))->toBe(Lang::get('sync::pairing.safety_number_changed'));
    expect($component->get('awaitingPeer'))->toBeFalse();
});
