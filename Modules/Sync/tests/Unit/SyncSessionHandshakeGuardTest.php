<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Exceptions\SessionNotAuthenticatedException;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\SyncSession;

uses(RefreshDatabase::class);

// Every method guarded here is one a caller could reach by wiring the transport
// up in the wrong order, and the guard is the difference between a loud failure
// and a user's ledger leaving the device unencrypted because the AEAD state was
// never established.
beforeEach(function (): void {
    $this->session = app(SyncSession::class);
});

function guardEntry(): OpLogEntry
{
    return new OpLogEntry(
        table: 'transactions',
        pk: 42,
        field: 'amount_minor',
        value: '1000',
        hlcL: 1750000000000,
        hlcC: 1,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: str_repeat('s', 88),
        userId: 1,
    );
}

it('starts out handshaking, with no peer identified', function (): void {
    expect($this->session->status())->toBe('handshaking')
        ->and($this->session->peerDeviceId())->toBeNull();
});

it('refuses to encrypt before the handshake has established a key', function (): void {
    $this->session->encrypt('plaintext');
})->throws(SessionNotAuthenticatedException::class, 'session not authenticated yet');

it('refuses to decrypt before the handshake has established a key', function (): void {
    $this->session->decrypt('ciphertext');
})->throws(SessionNotAuthenticatedException::class, 'session not authenticated yet');

it('refuses to receive ops before the handshake', function (): void {
    // Accepting here would mean replaying entries whose sender was never
    // authenticated into the user's ledger.
    $this->session->receiveOps('ciphertext', 1, ['device-a' => str_repeat('a', 64)]);
})->throws(SessionNotAuthenticatedException::class, 'session not authenticated yet');

it('can be closed before it was ever opened', function (): void {
    // A connection that drops mid-handshake still gets closed by the caller,
    // and there is no session row to update yet.
    $this->session->close();

    expect($this->session->status())->toBe('closed');
});

it('stays closed for encryption after close', function (): void {
    $this->session->close();

    $this->session->encrypt('plaintext');
})->throws(SessionNotAuthenticatedException::class, 'session not authenticated yet');
