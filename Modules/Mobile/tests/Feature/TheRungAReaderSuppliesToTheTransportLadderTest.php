<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Sync\Public\Services\PeerLanAddressBook;

uses(RefreshDatabase::class);

// The ladder has three rungs and the middle one had no implementation: a
// network that answers no browse left the dial falling straight through to an
// address guessed from the relay endpoint's host, and a household on such a
// network had no way to say where its desktop actually is.

function ladderPeer(int $userId, string $deviceId): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->userId = (int) User::query()->create([
        'username' => 'ladder-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ])->id;

    $this->peer = 'device-desktop-ladder';
    ladderPeer($this->userId, $this->peer);

    $this->book = app(PeerLanAddressBook::class);
    $this->ladder = app(PeerLanAddress::class);
});

it('dials what a reader typed when discovery has nothing remembered', function (): void {
    $this->book->setManual($this->userId, $this->peer, '10.1.2.3', 8100);

    expect($this->ladder->recall($this->userId))->toBe(['host' => '10.1.2.3', 'port' => 8100]);
});

it('prefers where the peer was last reached over what a reader typed', function (): void {
    $this->book->setManual($this->userId, $this->peer, '10.1.2.3', 8100);
    $this->book->remember($this->userId, $this->peer, '192.168.1.20', 8100);

    // Discovery first is the order the requirement names, and the better one:
    // a remembered address is where this device actually reached the peer.
    expect($this->ladder->recall($this->userId))->toBe(['host' => '192.168.1.20', 'port' => 8100]);
});

it('keeps the typed address when a failed dial forgets the discovered one', function (): void {
    $this->book->setManual($this->userId, $this->peer, '10.1.2.3', 8100);
    $this->book->remember($this->userId, $this->peer, '192.168.1.20', 8100);

    // What forget() is for: the peer moved. A fallback that a failed dial
    // erases alongside the address that failed is not a fallback at all.
    $this->book->forget($this->userId, $this->peer);

    expect($this->ladder->recall($this->userId))->toBe(['host' => '10.1.2.3', 'port' => 8100]);
});

it('has nothing to offer once the reader clears it', function (): void {
    $this->book->setManual($this->userId, $this->peer, '10.1.2.3', 8100);
    $this->book->setManual($this->userId, $this->peer, null, null);

    expect($this->book->manual($this->userId, $this->peer))->toBeNull();
    expect($this->ladder->recall($this->userId))->toBeNull();
});
