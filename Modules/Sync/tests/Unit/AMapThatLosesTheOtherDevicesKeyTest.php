<?php

declare(strict_types=1);

use Modules\Sync\Internal\Merge\Strategies\JsonKeyUnionStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// `transactions.field_provenance` holds one key per protected column, and those
// columns merge independently. FieldProvenanceWriter puts the WHOLE merged map
// on the wire on every stamp -- deliberately, because a delta would drop every
// key the receiver had not just been told about -- but under whole-value LWW the
// later map replaces the earlier one, so the key the OTHER device stamped is the
// one that goes. Nothing unsets a key locally, so no single device can do this.

function amltEntry(?string $value, int $hlcL, string $deviceId): OpLogEntry
{
    return new OpLogEntry(
        table: 'transactions',
        pk: 41,
        field: 'field_provenance',
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $deviceId,
        opType: OpType::Set,
        signature: str_repeat('0', 128),
        userId: 1,
    );
}

it('keeps a key each device stamped', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        amltEntry('{"note":"manual"}', 100, 'device-a'),
        amltEntry('{"category_id":"manual"}', 200, 'device-b'),
    ]);

    expect($resolved)->toBe(['note' => 'manual', 'category_id' => 'manual']);
});

// What the column did before, and what every other column still does. Pinned
// beside the fix so the difference is the thing being read, not a claim.
it('is exactly the key last-writer-wins would have dropped', function (): void {
    $entries = [
        amltEntry('{"note":"manual"}', 100, 'device-a'),
        amltEntry('{"category_id":"manual"}', 200, 'device-b'),
    ];

    $lww = (new LwwPerFieldStrategy)->resolve($entries);

    expect($lww)->toBe(['category_id' => 'manual'])
        ->and($lww)->not->toHaveKey('note');
});

// `{"0":"manual"}` decodes to [0 => 'manual'], which PHP cannot tell from the
// list ["manual"] -- so the guard refuses it rather than guessing. No key this
// column carries is numeric: they are column names. The merge below uses
// array_replace regardless, because a spread would renumber an integer key
// instead of merging on it and the refusal is the only thing stopping that.
it('refuses an object whose keys cannot be told from a list', function (): void {
    expect(fn () => (new JsonKeyUnionStrategy)->resolve([amltEntry('{"0":"manual"}', 100, 'device-a')]))
        ->toThrow(UnexpectedValueException::class);
});

it('lets a later stamp of the same key win', function (): void {
    $resolved = (new JsonKeyUnionStrategy)->resolve([
        amltEntry('{"note":"rule"}', 100, 'device-a'),
        amltEntry('{"note":"manual"}', 200, 'device-b'),
    ]);

    expect($resolved)->toBe(['note' => 'manual']);
});

// A field no op ever gave a map to is absent, not empty: writing {} on the
// receiver while the origin keeps its NULL is a divergence no later op closes.
it('answers null where no op ever carried a map', function (): void {
    expect((new JsonKeyUnionStrategy)->resolve([
        amltEntry(null, 100, 'device-a'),
        amltEntry(null, 200, 'device-b'),
    ]))->toBeNull();
});

it('skips a tombstone between two maps rather than forgetting them', function (): void {
    expect((new JsonKeyUnionStrategy)->resolve([
        amltEntry('{"note":"manual"}', 100, 'device-a'),
        amltEntry(null, 150, 'device-b'),
        amltEntry('{"counterparty_id":"manual"}', 200, 'device-a'),
    ]))->toBe(['note' => 'manual', 'counterparty_id' => 'manual']);
});

// Coercing a malformed value to [] would drop every key with no signal, which is
// the failure this strategy exists to stop. The replayer catches the throw and
// quarantines the op as a strategy error.
it('refuses a value that is not an object of keys', function (): void {
    expect(fn () => (new JsonKeyUnionStrategy)->resolve([amltEntry('["note"]', 100, 'device-a')]))
        ->toThrow(UnexpectedValueException::class);

    expect(fn () => (new JsonKeyUnionStrategy)->resolve([amltEntry('"manual"', 100, 'device-a')]))
        ->toThrow(UnexpectedValueException::class);
});

// A build whose create path encoded the column's stored TEXT rather than the map
// inside it signed those ops, so their bytes can never be corrected where they
// are stored. The one wrapping is unwrapped; anything else is still refused.
it('unwraps a map an older build encoded twice', function (): void {
    expect((new JsonKeyUnionStrategy)->resolve([
        amltEntry(json_encode('{"note":"manual"}', JSON_THROW_ON_ERROR), 100, 'device-a'),
    ]))->toBe(['note' => 'manual']);

    expect(fn () => (new JsonKeyUnionStrategy)->resolve([
        amltEntry(json_encode('["note"]', JSON_THROW_ON_ERROR), 100, 'device-a'),
    ]))->toThrow(UnexpectedValueException::class);
});
