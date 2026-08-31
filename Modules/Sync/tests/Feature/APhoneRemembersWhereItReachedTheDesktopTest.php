<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairedDeviceAdmitter;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Services\PeerLanAddressBook;

uses(RefreshDatabase::class);

// A phone that paired by typed word code reached the desktop to fetch its
// offer and then threw the address away: the only address the sync dial ever
// had came from a scanned QR's relay endpoint, so the typed-code arm pulled
// with lanHost=null, ran the relay leg alone, and sat at "0 of 0 records"
// under copy telling the reader to check a network that was already fine.
// Reproduced on a paired Galaxy S24 against a desktop on the same subnet.

function fakeDiscovery(DiscoveredPeer ...$peers): PeerDiscovery
{
    return new class($peers) implements PeerDiscovery
    {
        /** @param list<DiscoveredPeer> $peers */
        public function __construct(private array $peers) {}

        public array $browsed = [];

        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            $this->browsed[] = $serviceType;

            return $this->peers;
        }

        public function reach(): LanDiscoveryReach
        {
            return LanDiscoveryReach::Available;
        }
    };
}

function addressBookOver(PeerDiscovery $discovery): PeerLanAddressBook
{
    return new PeerLanAddressBook(
        app(DatabaseManager::class),
        new LanPeerBrowser(app(HttpFactory::class), $discovery),
    );
}

function peerRow(int $userId, string $deviceId, ?string $host = null, ?int $port = null): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Mac',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-08-29T00:00:00Z',
        'confirmed_at' => '2026-08-29T00:00:00Z',
        'last_lan_host' => $host,
        'last_lan_port' => $port,
        'created_at' => '2026-08-29T00:00:00Z',
        'updated_at' => '2026-08-29T00:00:00Z',
    ]);
}

it('browses for a peer it has no address for, and keeps what it finds', function (): void {
    peerRow(7, 'desk-1');

    $discovery = fakeDiscovery(new DiscoveredPeer('desk-1', '192.168.1.9', 51337, DiscoveryMode::Mdns));
    $book = addressBookOver($discovery);

    expect($book->recall(7, 'desk-1'))->toBeNull()
        ->and($book->locate(7, 'desk-1'))->toBe(['host' => '192.168.1.9', 'port' => 51337])
        // Kept, so the next tick and the next launch dial straight out.
        ->and($book->recall(7, 'desk-1'))->toBe(['host' => '192.168.1.9', 'port' => 51337]);
});

it('dials the remembered address without asking the network again', function (): void {
    peerRow(7, 'desk-1', '192.168.1.9', 51337);

    $discovery = fakeDiscovery();
    $book = addressBookOver($discovery);

    expect($book->locate(7, 'desk-1'))->toBe(['host' => '192.168.1.9', 'port' => 51337])
        // A browse burns its whole timeout every time it runs; the record
        // exists so the hot path never pays it.
        ->and($discovery->browsed)->toBe([]);
});

it('never hands back another device s address', function (): void {
    peerRow(7, 'desk-1', '192.168.1.9', 51337);
    peerRow(7, 'desk-2');

    $book = addressBookOver(fakeDiscovery());

    expect($book->recall(7, 'desk-2'))->toBeNull();
});

it('forgets an address that stopped answering so the next look is a browse', function (): void {
    peerRow(7, 'desk-1', '192.168.1.9', 51337);

    $discovery = fakeDiscovery(new DiscoveredPeer('desk-1', '192.168.1.44', 51337, DiscoveryMode::Mdns));
    $book = addressBookOver($discovery);

    $book->forget(7, 'desk-1');

    expect($book->recall(7, 'desk-1'))->toBeNull()
        ->and($book->locate(7, 'desk-1'))->toBe(['host' => '192.168.1.44', 'port' => 51337]);
});

// The other half: the offer fetch is the one moment the responder sees where
// the initiator is, and a browse at sync time is not free — on a runtime whose
// multicast is ungranted it is not even possible. So the address rides the
// pairing row through admit, exactly as initiator_name already does.
it('admits the desktop with the address the pairing reached it on', function (): void {
    $row = (object) [
        'initiator_device_id' => 'desk-9',
        'initiator_ed25519_pub_hex' => str_repeat('c', 64),
        'initiator_x25519_pub_hex' => str_repeat('d', 64),
        'responder_ed25519_pub_hex' => str_repeat('e', 64),
        'initiator_name' => 'Mac',
        'initiator_lan_host' => '192.168.1.77',
        'initiator_lan_port' => 51337,
    ];

    app(PairedDeviceAdmitter::class)->admitInitiatorDevice($row, 11);

    expect(addressBookOver(fakeDiscovery())->recall(11, 'desk-9'))
        ->toBe(['host' => '192.168.1.77', 'port' => 51337]);
});

it('does not blank a known address when a later admit carries none', function (): void {
    $base = [
        'initiator_device_id' => 'desk-9',
        'initiator_ed25519_pub_hex' => str_repeat('c', 64),
        'initiator_x25519_pub_hex' => str_repeat('d', 64),
        'responder_ed25519_pub_hex' => str_repeat('e', 64),
        'initiator_name' => 'Mac',
    ];

    $admitter = app(PairedDeviceAdmitter::class);
    $admitter->admitInitiatorDevice((object) [...$base, 'initiator_lan_host' => '192.168.1.77', 'initiator_lan_port' => 51337], 11);
    // A re-pair over the relay knows the device but not where it is.
    $admitter->admitInitiatorDevice((object) [...$base, 'initiator_lan_host' => null, 'initiator_lan_port' => null], 11);

    expect(addressBookOver(fakeDiscovery())->recall(11, 'desk-9'))
        ->toBe(['host' => '192.168.1.77', 'port' => 51337]);
});
