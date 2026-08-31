<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

// The input's placeholder promised four groups where a real code carries seven,
// so filling the placeholder produced a code the decoder could not read — and
// the answer was "invalid or has expired. Ask the other device to generate a
// new one", advice that does nothing for a code the reader had not finished.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'half-typed',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    // A readable code is now weighed against the network, and nothing here
    // fakes the multicast socket underneath the browse: without this the answer
    // would come from whatever happens to be advertising on the machine running
    // the suite, and CI could not see the difference.
    htpNothingIsAdvertising();
});

/** @param list<DiscoveredPeer> $peers */
function htpDiscovers(array $peers): void
{
    app()->instance(PeerDiscovery::class, new class($peers) implements PeerDiscovery
    {
        /** @param list<DiscoveredPeer> $peers */
        public function __construct(private readonly array $peers) {}

        public function reach(): LanDiscoveryReach
        {
            return LanDiscoveryReach::Available;
        }

        /** @return list<DiscoveredPeer> */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return $this->peers;
        }
    });
}

function htpNothingIsAdvertising(): void
{
    htpDiscovers([]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);
}

function htpOneDeviceAnswersAndRefuses(): void
{
    htpDiscovers([new DiscoveredPeer('a-desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);
}

function htpRefusal(User $user, string $wordCode): string
{
    return (string) Livewire::actingAs($user)->test(PairingFlowModal::class)
        ->set('wordCode', $wordCode)
        ->call('submitCode')
        ->get('flashMessage');
}

it('does not answer a half-typed code and an unknown one with one sentence', function (): void {
    $wellFormed = app(WordCodeEncoder::class)->encode(str_repeat('ab', 16));

    expect(htpRefusal($this->user, 'ABCD-EFGH-IJKL-MNOP'))
        ->not->toBe(htpRefusal($this->user, $wellFormed));
});

it('tells a reader who typed too few letters that the code is unfinished', function (): void {
    expect(htpRefusal($this->user, 'ABCD-EFGH-IJKL-MNOP'))
        ->toBe(Lang::get('sync::pairing.code_incomplete'));
});

// This asked for a fresh code, which is the one thing a reader whose code is
// live cannot act on. Nothing local can tell an expired code from one issued on
// the device across the desk, so the answer now says what was observed: which
// peer answered, and whether any did.
it('names which of the two silences happened rather than declaring the code dead', function (): void {
    $wellFormed = app(WordCodeEncoder::class)->encode(str_repeat('ab', 16));

    htpOneDeviceAnswersAndRefuses();

    expect(htpRefusal($this->user, $wellFormed))
        ->toBe(Lang::get('sync::pairing.code_not_accepted'));

    htpNothingIsAdvertising();

    expect(htpRefusal($this->user, $wellFormed))
        ->toBe(Lang::get('sync::pairing.no_peer_answered'));
});

// The placeholder is the only statement the screen makes about how long a code
// is, and it promised four groups where encode() emits seven — so a reader who
// filled exactly what was shown could not produce a readable code at all.
it('shows a placeholder shaped like the code the encoder emits', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php')
    );
    $shape = preg_replace('/[A-Z2-7]/', 'X', app(WordCodeEncoder::class)->encode(str_repeat('ab', 16)));

    expect($blade)->toContain('placeholder="'.$shape.'"');
});
