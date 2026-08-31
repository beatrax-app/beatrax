<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

// Two desktops, two databases. The device typing the code is the acting
// Livewire user on the default connection; the device that ISSUED it holds its
// row on the harness's peer connection, and answers the pairing offer from
// there — which is the whole point: the typed token names a row this side has
// never held.

const TYPED_ACROSS_PEER_USER_ID = 90310;

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

function typedAcrossUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/** @param list<DiscoveredPeer> $peers */
function typedAcrossDiscovers(array $peers, LanDiscoveryReach $reach = LanDiscoveryReach::Available): void
{
    app()->instance(PeerDiscovery::class, new class($peers, $reach) implements PeerDiscovery
    {
        /** @param list<DiscoveredPeer> $peers */
        public function __construct(private readonly array $peers, private readonly LanDiscoveryReach $reach) {}

        public function reach(): LanDiscoveryReach
        {
            return $this->reach;
        }

        /** @return list<DiscoveredPeer> */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return $this->peers;
        }
    });
}

it('accepts a code whose token row exists only in the database of the device that issued it', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-accepts');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    $responder = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    /** @var array{0: DeviceIdentityDto, 1: string} $issued */
    $issued = $this->asDevice('peer', function () use ($session): array {
        $identity = app(DeviceIdentityService::class)->generateAndPersist(TYPED_ACROSS_PEER_USER_ID, $session);
        $token = app(PairingTokenService::class)->issue(
            TYPED_ACROSS_PEER_USER_ID,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        return [$identity, $token];
    });

    [$initiator, $token] = $issued;

    typedAcrossDiscovers([new DiscoveredPeer('the-other-desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);

    // The offer is served by the issuing device's OWN PairingOfferService,
    // reading its OWN database, so a token it does not hold really 404s.
    Http::fake(function (ClientRequest $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $asked = is_string($query['token'] ?? null) ? $query['token'] : '';

        $offer = $this->asDevice('peer', fn () => app(PairingOfferService::class)->offerFor($asked, TYPED_ACROSS_PEER_USER_ID));

        return $offer === null ? Http::response(['error' => 'not_found'], 404) : Http::response($offer);
    });

    $component = Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', app(WordCodeEncoder::class)->encode($token))
        ->call('submitCode');

    $component->assertSet('flashMessage', '')
        ->assertSet('step', 'confirm');

    expect($component->get('safetyWords'))->toHaveCount(6);

    // The device that issued the code has to LEARN it was accepted, or it sits
    // on its own "show code" step until the token lapses.
    $this->asDevice('peer', function () use ($responder): void {
        app(PairingGateway::class)->drainPairingFrames(TYPED_ACROSS_PEER_USER_ID, null);

        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('user_id', TYPED_ACROSS_PEER_USER_ID)
            ->first();

        expect($row->state)->toBe(PairingState::AwaitingConfirm->value)
            ->and($row->responder_device_id)->toBe($responder->deviceId);
    });

    // Both sides confirm the same six words, and both reach CONFIRMED.
    $component->call('confirmMatch');

    $this->asDevice('peer', function () use ($initiator, $responder, $session): void {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('user_id', TYPED_ACROSS_PEER_USER_ID)
            ->first();

        app(PairingGateway::class)->drainPairingFrames(TYPED_ACROSS_PEER_USER_ID, null);

        $state = app(PairingTokenService::class)->confirm(
            (int) $row->id,
            TYPED_ACROSS_PEER_USER_ID,
            $initiator->deviceId,
            PairingSafetyDigest::forToken((int) $row->id, TYPED_ACROSS_PEER_USER_ID),
        );

        expect($state)->toBe(PairingState::Confirmed->value);
        expect(app(DeviceRegistryService::class)->deviceKeys(TYPED_ACROSS_PEER_USER_ID))->toHaveKey($responder->deviceId);

        app(PairingGateway::class)->sendConfirm(TYPED_ACROSS_PEER_USER_ID, (int) $row->id, $responder->deviceId, $session);
    });

    $component->call('checkPairingState')->assertSet('step', 'success');

    expect(app(DeviceRegistryService::class)->deviceKeys((int) $user->id))->toHaveKey($initiator->deviceId);
});

it('does not call a good code invalid because some other device on the network refused it', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-stranger');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    typedAcrossDiscovers([new DiscoveredPeer('a-housemates-mac', '192.0.2.9', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', app(WordCodeEncoder::class)->encode(bin2hex(random_bytes(16))))
        ->call('submitCode')
        ->assertSet('step', 'enter_code')
        ->assertSet('flashMessage', Lang::get('sync::pairing.code_not_accepted'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('sync::pairing.invalid_code'));
});

it('never blames the network for a silence it cannot explain', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-cannot-look');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    typedAcrossDiscovers([], LanDiscoveryReach::Unsupported);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', app(WordCodeEncoder::class)->encode(bin2hex(random_bytes(16))))
        ->call('submitCode')
        ->assertSet('flashMessage', Lang::get('sync::pairing.no_peer_search'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('sync::pairing.no_peer_answered'));
});

it('tells a rate-limited device to wait rather than to burn a fresh code', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-limited');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    typedAcrossDiscovers([new DiscoveredPeer('the-other-desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'rate_limited'], 429)]);

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', app(WordCodeEncoder::class)->encode(bin2hex(random_bytes(16))))
        ->call('submitCode')
        ->assertSet('flashMessage', Lang::get('sync::pairing.rate_limited'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('sync::pairing.invalid_code'));
});

it('says nothing answered when the search ran and found no peer at all', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-silent');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    typedAcrossDiscovers([]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', app(WordCodeEncoder::class)->encode(bin2hex(random_bytes(16))))
        ->call('submitCode')
        ->assertSet('flashMessage', Lang::get('sync::pairing.no_peer_answered'));
});

it('still refuses an unreadable code without asking the network at all', function (): void {
    $this->crossDevicePairingSetUp();

    $user = typedAcrossUser('typed-code-truncated');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    typedAcrossDiscovers([new DiscoveredPeer('the-other-desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', 'ABCD')
        ->call('submitCode')
        ->assertSet('flashMessage', Lang::get('sync::pairing.code_incomplete'));

    Http::assertNothingSent();
});
