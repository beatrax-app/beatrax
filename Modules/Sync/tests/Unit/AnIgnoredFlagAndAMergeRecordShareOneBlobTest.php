<?php

declare(strict_types=1);

use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Internal\Merge\Strategies\JsonKeyUnionStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// `counterparties.metadata` carries four independent facts: the ignored flag,
// the subcategory, the default-name provenance, and the list a destructive
// merge absorbed. Every writer read-modify-writes the whole blob, so under
// whole-value LWW the later map replaces the earlier one entirely.

function iafmEntry(?string $value, int $hlcL, string $deviceId): OpLogEntry
{
    return new OpLogEntry(
        table: 'counterparties',
        pk: 77,
        field: 'metadata',
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $deviceId,
        opType: OpType::Set,
        signature: str_repeat('0', 128),
        userId: 1,
    );
}

it('keeps both the ignored flag and the merge record', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        iafmEntry('{"ignored":true}', 100, 'desktop'),
        iafmEntry('{"merged_from":[{"slug":"acme-bv"}]}', 200, 'phone'),
    ]);

    expect($resolved)->toBe([
        'ignored' => true,
        'merged_from' => [['slug' => 'acme-bv']],
    ]);
});

// A merge has no undo, so the absorbed list is the only record of what the
// surviving row used to be. Pinned beside the fix so the difference is read
// rather than claimed.
it('is exactly what last-writer-wins dropped', function (): void {
    $mergeLater = (new LwwPerFieldStrategy)->resolve([
        iafmEntry('{"ignored":true}', 100, 'desktop'),
        iafmEntry('{"merged_from":[{"slug":"acme-bv"}]}', 200, 'phone'),
    ]);

    // The strategy takes the last element: its callers hand it HLC-sorted.
    $ignoreLater = (new LwwPerFieldStrategy)->resolve([
        iafmEntry('{"merged_from":[{"slug":"acme-bv"}]}', 100, 'phone'),
        iafmEntry('{"ignored":true}', 200, 'desktop'),
    ]);

    expect($mergeLater)->toBe(['merged_from' => [['slug' => 'acme-bv']]])
        ->and($mergeLater)->not->toHaveKey('ignored')
        ->and($ignoreLater)->toBe(['ignored' => true])
        ->and($ignoreLater)->not->toHaveKey('merged_from');
});

// A rename drops the flag saying the name is the app's. A key union cannot
// carry an absence, so LabelCounterparty writes the key as a present null --
// which tokenIn() has always read as "no token".
it('clears the default-name flag through a present null', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        iafmEntry('{"default_name":"unknown","ignored":true}', 100, 'desktop'),
        iafmEntry('{"default_name":null,"ignored":true}', 200, 'phone'),
    ]);

    expect($resolved)->toBe(['default_name' => null, 'ignored' => true])
        ->and(CounterpartyDefaultName::tokenIn($resolved))->toBeNull();
});

// The same clear expressed as an absence is what an unset would have sent, and
// a union cannot tell it from "this device never held the key".
it('cannot express the clear as an absence', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        iafmEntry('{"default_name":"unknown","ignored":true}', 100, 'desktop'),
        iafmEntry('{"ignored":true}', 200, 'phone'),
    ]);

    expect(CounterpartyDefaultName::tokenIn($resolved))->toBe('unknown');
});
