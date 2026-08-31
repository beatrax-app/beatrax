<?php

declare(strict_types=1);

use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Public\Transport\SensitiveTextBudget;

// The two numbers are a pair: raise the character budget and the entry stops
// fitting in a frame, which is the failure PeerCatchUpExchanger now skips
// around rather than wedging on. This is the only place they are compared, so
// it is the only place a future edit to either one is answered.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#one-entry-that-can-never-be-framed
 */
function budgetSealedEntry(string $plaintext): OpLogEntry
{
    // Exactly what OpLogWriter does: json_encode FIRST (which escapes every
    // non-ASCII character to \uXXXX), then seal, then base64.
    $sealed = (new OpLogFieldCrypto)->encrypt(
        (string) json_encode($plaintext, JSON_THROW_ON_ERROR),
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        'op-log|transactions|1|note|1',
    );

    return new OpLogEntry(
        table: 'transactions',
        pk: 999999999,
        field: 'note',
        value: $sealed,
        hlcL: 1750000000000,
        hlcC: 7,
        deviceId: str_repeat('d', 64),
        opType: OpType::Set,
        signature: str_repeat('s', 88),
        userId: 999999999999,
        gdkEpoch: 9007199254740991,
    );
}

it('frames a sealed note written at the whole character budget, in any script', function (string $character): void {
    $note = str_repeat($character, SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS);

    expect(mb_strlen($note))->toBe(SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS)
        ->and((new TransportFramer)->exceedsFrameBudget(budgetSealedEntry($note)))->toBeFalse();
})->with([
    // Cheapest: one JSON byte per character.
    'ascii' => ['v'],
    // Six JSON bytes per character — every Cyrillic, Greek and Hebrew note.
    'basic multilingual plane' => ['Ж'],
    // Twelve: a surrogate pair, which is what an emoji costs.
    'astral plane' => ["\u{1F600}"],
]);

it('would not frame the same note at double the budget, so the pairing is real', function (): void {
    $note = str_repeat("\u{1F600}", SensitiveTextBudget::MAX_PLAINTEXT_CHARACTERS * 2);

    expect((new TransportFramer)->exceedsFrameBudget(budgetSealedEntry($note)))->toBeTrue();
});
