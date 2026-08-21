<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
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

// The poll re-emits this side's PAIR_CONFIRM so a frame the peer deferred or
// lost is sent again. What it must never do is assert a confirmation the trust
// gate refused: the flag it used to read is set by the refusal path too, so a
// tap against stale safety words shipped a signed confirm every three seconds.

function reEmitUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('re-emit-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// No relay and no peer on the wire, so an outbound confirm lands in the local
// holding space — which is what makes "did it send one" countable at all.
function reEmitQueuedFrames(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('relay_mailbox')->count();
}

function reEmitDiscoversNothing(): void
{
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
}

/**
 * @return array{0: Testable, 1: User, 2: string}
 */
function reEmitModalOnConfirmStep(string $username): array
{
    $user = reEmitUser($username);
    test()->actingAs($user);
    reEmitDiscoversNothing();

    // The machine running this may have a real relay configured in its own user
    // data path, and an unfaked courier reached it over the wire. Refusing every
    // request puts the frame in the local holding space either way.
    Http::fake(['*' => Http::response('', 503)]);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');

    $accepted = app(PairingTokenService::class)->accept(
        app(WordCodeEncoder::class)->decode($component->get('wordCode')),
        (int) $user->id,
        '11111111-2222-4333-8444-555555555555',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );
    expect($accepted)->not->toBeFalse();

    // The poll is what derives the words the confirmation is bound to.
    $component->call('checkPairingState')->assertSet('step', 'confirm');

    return [$component, $user, (string) $component->get('pairingTokenId')];
}

it('re-emits this side confirm on every poll once the tap was recorded', function (): void {
    [$component] = reEmitModalOnConfirmStep('re-emit-recorded');

    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    $afterTap = reEmitQueuedFrames();
    $component->call('checkPairingState');

    expect(reEmitQueuedFrames())->toBeGreaterThan($afterTap);
});

it('ships nothing for a tap the safety-number gate refused', function (): void {
    [$component, $user, $tokenId] = reEmitModalOnConfirmStep('re-emit-refused');

    // The responder rebinds after the words were derived, so the digest the tap
    // carries no longer matches the keys the row holds.
    app(PairingTokenService::class)->applyResponderAccept(
        (int) $user->id,
        (string) app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('id', (int) $tokenId)->value('token_hash'),
        '99999999-8888-4777-8666-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
    );

    $component->call('confirmMatch')
        ->assertSet('awaitingPeer', false)
        ->assertSet('flashMessage', Lang::get('sync::pairing.safety_number_changed'));

    $afterRefusal = reEmitQueuedFrames();

    $component->call('checkPairingState');
    $component->call('checkPairingState');

    expect(reEmitQueuedFrames())->toBe($afterRefusal);
});

// A closed and reopened modal has forgotten it tapped; the row has not, and it
// is the row that decides. Without this the desktop stops re-emitting and the
// phone waits for a confirm that is never sent again.
it('keeps re-emitting after the modal was closed and reopened', function (): void {
    [$component, $user] = reEmitModalOnConfirmStep('re-emit-reopened');

    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    $reopened = Livewire::test(PairingFlowModal::class)
        ->call('openModal')
        ->assertSet('step', 'confirm')
        ->assertSet('awaitingPeer', true);

    $afterReopen = reEmitQueuedFrames();
    $reopened->call('checkPairingState');

    expect(reEmitQueuedFrames())->toBeGreaterThan($afterReopen);
    expect((int) $user->id)->toBeGreaterThan(0);
});
