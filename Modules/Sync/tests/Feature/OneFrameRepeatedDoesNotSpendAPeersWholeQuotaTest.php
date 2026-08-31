<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;

uses(RefreshDatabase::class);

// The poll re-emits its accept every three seconds, and each re-emit is the
// same bytes: same token hash, same keys, same name. Appending them filled the
// per-peer cap with sixteen copies of one frame in forty-eight seconds, and
// every later frame to that peer was refused until they expired a month on.

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function quotaFrame(array $extra = []): array
{
    return [...['type' => 'PAIR_RESPONDER_ACCEPT', 'token_hash' => 'hash-1'], ...$extra];
}

function quotaPending(string $recipientDid): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->whereNull('delivered_at')
        ->count();
}

it('holds one copy of a frame however many times the poll re-sends it', function (): void {
    $outbox = app(PairingPeerOutbox::class);

    foreach (range(1, 20) as $ignored) {
        expect($outbox->queueFor('phone', 'desktop', quotaFrame()))->toBeTrue();
    }

    // Already held is already stored, so every re-emit after the first is an
    // answer rather than a row.
    expect(quotaPending('desktop'))->toBe(1);
});

it('leaves room for the frames that follow it', function (): void {
    $outbox = app(PairingPeerOutbox::class);

    foreach (range(1, 20) as $ignored) {
        $outbox->queueFor('phone', 'desktop', quotaFrame());
    }

    // The confirm is the frame the accept's duplicates used to lock out, and
    // it is the one the ceremony cannot finish without.
    expect($outbox->queueFor('phone', 'desktop', quotaFrame(['type' => 'PAIR_CONFIRM'])))->toBeTrue()
        ->and(quotaPending('desktop'))->toBe(2);
});

it('still refuses a peer that floods it with frames that genuinely differ', function (): void {
    $outbox = app(PairingPeerOutbox::class);

    foreach (range(1, 16) as $n) {
        expect($outbox->queueFor('phone', 'desktop', quotaFrame(['token_hash' => 'hash-'.$n])))->toBeTrue();
    }

    // The cap is a flood guard and stays one: folding duplicates must not turn
    // it into a door that never closes.
    expect($outbox->queueFor('phone', 'desktop', quotaFrame(['token_hash' => 'hash-17'])))->toBeFalse()
        ->and(quotaPending('desktop'))->toBe(16);
});

it('keeps one peer flooding another peer out of the way', function (): void {
    $outbox = app(PairingPeerOutbox::class);

    foreach (range(1, 16) as $n) {
        $outbox->queueFor('phone', 'desktop', quotaFrame(['token_hash' => 'hash-'.$n]));
    }

    expect($outbox->queueFor('phone', 'laptop', quotaFrame()))->toBeTrue()
        ->and(quotaPending('laptop'))->toBe(1);
});
