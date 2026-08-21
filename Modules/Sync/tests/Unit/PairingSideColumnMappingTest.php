<?php

declare(strict_types=1);

use Modules\Sync\Public\Enums\PairingSide;

// Getting this mapping backwards writes a device's own confirmation into the
// peer's column: both stamps land on one side, bothConfirmed() never trips, and
// the handshake stalls with no exception anywhere. So each side is asserted
// against the literal column names rather than against the enum's own helpers.
it('maps each side to its own confirmed_at column and the peer to the other', function (): void {
    expect(PairingSide::Initiator->confirmedAtColumn())->toBe('initiator_confirmed_at')
        ->and(PairingSide::Initiator->peerConfirmedAtColumn())->toBe('responder_confirmed_at')
        ->and(PairingSide::Responder->confirmedAtColumn())->toBe('responder_confirmed_at')
        ->and(PairingSide::Responder->peerConfirmedAtColumn())->toBe('initiator_confirmed_at');
});

it('never resolves a side to the same column twice', function (PairingSide $side): void {
    expect($side->confirmedAtColumn())->not->toBe($side->peerConfirmedAtColumn())
        ->and($side->columnPrefix())->not->toBe($side->peerPrefix())
        ->and($side->peer())->not->toBe($side)
        ->and($side->peer()->peer())->toBe($side);
})->with([PairingSide::Initiator, PairingSide::Responder]);

it('prefixes both sides of every other paired column', function (): void {
    expect(PairingSide::Initiator->columnPrefix())->toBe('initiator_')
        ->and(PairingSide::Initiator->peerPrefix())->toBe('responder_')
        ->and(PairingSide::Responder->columnPrefix())->toBe('responder_')
        ->and(PairingSide::Responder->peerPrefix())->toBe('initiator_');
});

// The backing values are written into pairing_tokens and cross between devices
// inside a seeded row, so a rename would strand every stored handshake. tryFrom
// is what a resumed Livewire screen hydrates its wire string through, and an
// unrecognised value has to read as null there rather than throw.
it('keeps the stored spelling and hydrates an unknown one as null', function (): void {
    expect(PairingSide::Initiator->value)->toBe('initiator')
        ->and(PairingSide::Responder->value)->toBe('responder')
        ->and(PairingSide::tryFrom('initiator'))->toBe(PairingSide::Initiator)
        ->and(PairingSide::tryFrom('responder'))->toBe(PairingSide::Responder)
        ->and(PairingSide::tryFrom(''))->toBeNull()
        ->and(PairingSide::tryFrom('Initiator'))->toBeNull();
});
