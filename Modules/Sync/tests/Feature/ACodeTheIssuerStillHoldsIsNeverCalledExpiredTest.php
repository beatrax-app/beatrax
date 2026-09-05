<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
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
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Enums\PairingAcceptRefusal;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// The offer lookup that produces the initiator identity is the issuing device
// answering for its own live token, and PairingOfferService serves an
// AWAITING_CONFIRM row on purpose so a retry can ask again. The screen behind
// it then called the code invalid or expired and sent the reader for a
// replacement, ending a ceremony that was still running.

/** @param list<DiscoveredPeer> $peers */
function issuerHoldsDiscovers(array $peers, LanDiscoveryReach $reach = LanDiscoveryReach::Available): void
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

function issuerHoldsUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// A desktop on this network that answers for whatever token it is asked about,
// which is what the issuing device does for its own live code.
function issuerHoldsAnswersForAnyCode(): void
{
    issuerHoldsDiscovers([new DiscoveredPeer('the-other-desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);

    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-initiator',
        'ed25519' => bin2hex(random_bytes(32)),
        'x25519' => bin2hex(random_bytes(32)),
        'name' => 'The other desktop',
    ])]);
}

it('does not call a live code expired when this device has already taken it up', function (): void {
    $user = issuerHoldsUser('issuer-holds');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    issuerHoldsAnswersForAnyCode();

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', $wordCode)
        ->call('submitCode')
        ->assertSet('flashMessage', '')
        ->assertSet('step', 'confirm');

    // The same code submitted again — a double tap, or the modal reopened with
    // the code still typed. accept() refuses a row past `pending` with the same
    // bare false an unknown code gets, and that false used to be read as proof
    // the code was dead.
    Livewire::test(PairingFlowModal::class)
        ->call('enterACode')
        ->set('wordCode', $wordCode)
        ->call('submitCode')
        ->assertSet('step', 'enter_code')
        ->assertSet('flashMessage', Lang::get('sync::pairing.already_under_way'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('sync::pairing.invalid_code'))
        ->assertSet('flashMessage', fn (string $m): bool => ! str_contains($m, 'expired'));
});

it('keeps the three endings of a refused accept apart', function (): void {
    $user = issuerHoldsUser('issuer-holds-endings');
    $userId = (int) $user->id;
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    $responder = app(DeviceIdentityService::class)->generateAndPersist($userId, $session);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $token = bin2hex(random_bytes(16));

    // Nothing local, and nobody vouched: the only ending that may say the code
    // is unknown or expired.
    expect($gateway->classifyAcceptRefusal($token, $userId, issuerServedItsOffer: false))
        ->toBe(PairingAcceptRefusal::NotLiveHere);

    // Nothing local, but the minting device answered for it on this submit.
    expect($gateway->classifyAcceptRefusal($token, $userId, issuerServedItsOffer: true))
        ->toBe(PairingAcceptRefusal::VouchedByIssuer);

    /** @var PairingTokenService $tokens */
    $tokens = app(PairingTokenService::class);

    $tokens->seedFromInitiator($userId, new PairingPeerIdentity(
        'desktop-initiator',
        bin2hex(random_bytes(32)),
        bin2hex(random_bytes(32)),
        'The other desktop',
        null,
        null,
    ), $token);

    expect($tokens->accept(
        $token,
        $userId,
        $responder->deviceId,
        $responder->ed25519PublicKeyHex,
        $responder->x25519PublicKeyHex,
    ))->not->toBeFalse();

    expect($gateway->classifyAcceptRefusal($token, $userId, issuerServedItsOffer: true))
        ->toBe(PairingAcceptRefusal::AlreadyUnderWay);
});

it('carries both new refusal lines in all twenty-six locales', function (): void {
    $missing = [];

    foreach (['Modules/Sync/Resources/lang' => null, 'Modules/Mobile/Resources/lang' => 'errors'] as $root => $group) {
        $locales = array_values(array_filter(
            scandir(base_path($root)) ?: [],
            static fn (string $entry): bool => ! str_starts_with($entry, '.'),
        ));

        expect($locales)->toHaveCount(26);

        foreach ($locales as $locale) {
            /** @var array<string, mixed> $pairing */
            $pairing = require base_path($root.'/'.$locale.'/pairing.php');
            $lines = $group === null ? $pairing : ($pairing[$group] ?? []);

            foreach (['already_under_way', 'vouched_but_refused'] as $key) {
                $copy = is_array($lines) ? ($lines[$key] ?? null) : null;

                if (! is_string($copy) || $copy === '') {
                    $missing[] = $root.'/'.$locale.'.'.$key;
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
