<?php

declare(strict_types=1);

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;

// Every rejection here is a frame that would otherwise be interpreted, and a
// misinterpreted frame writes wrong data into someone's ledger rather than
// failing loudly. The length prefix is the only thing separating one op batch
// from the next on a stream.
function framerEntry(array $overrides = []): OpLogEntry
{
    return new OpLogEntry(
        table: $overrides['table'] ?? 'transactions',
        pk: $overrides['pk'] ?? 42,
        field: $overrides['field'] ?? 'amount_minor',
        value: $overrides['value'] ?? '1000',
        hlcL: $overrides['hlcL'] ?? 1750000000000,
        hlcC: $overrides['hlcC'] ?? 7,
        deviceId: $overrides['deviceId'] ?? 'device-a',
        opType: $overrides['opType'] ?? OpType::Set,
        signature: $overrides['signature'] ?? str_repeat('s', 88),
        userId: $overrides['userId'] ?? 1,
        gdkEpoch: $overrides['gdkEpoch'] ?? null,
    );
}

it('round-trips an op through the wire format unchanged', function (): void {
    $framer = new TransportFramer;
    $entry = framerEntry();

    $decoded = $framer->decode($framer->encode([$entry]));

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]->table)->toBe('transactions')
        ->and($decoded[0]->pk)->toBe(42)
        ->and($decoded[0]->field)->toBe('amount_minor')
        ->and($decoded[0]->value)->toBe('1000')
        ->and($decoded[0]->hlcL)->toBe(1750000000000)
        ->and($decoded[0]->hlcC)->toBe(7)
        ->and($decoded[0]->deviceId)->toBe('device-a')
        ->and($decoded[0]->opType)->toBe(OpType::Set)
        ->and($decoded[0]->userId)->toBe(1);
});

it('carries the GDK epoch, without which an encrypted value cannot be read', function (): void {
    $framer = new TransportFramer;

    $decoded = $framer->decode($framer->encode([framerEntry(['gdkEpoch' => 3])]));

    // The epoch tags which key encrypted the value. Dropping it in transit
    // leaves the receiving device holding ciphertext it cannot decrypt.
    expect($decoded[0]->gdkEpoch)->toBe(3);
});

it('prefixes the payload with its length as a little-endian uint32', function (): void {
    $framer = new TransportFramer;

    $frame = $framer->encode([framerEntry()]);
    $declared = unpack('Vlen', substr($frame, 0, 4))['len'];

    // A stream reader takes exactly this many bytes as one frame; if the
    // prefix disagreed with the payload the next frame would start mid-JSON.
    expect($declared)->toBe(strlen($frame) - 4);
});

it('keeps a string primary key a string rather than coercing it to an int', function (): void {
    $framer = new TransportFramer;

    $decoded = $framer->decode($framer->encode([framerEntry(['pk' => 'uuid-abc-123'])]));

    expect($decoded[0]->pk)->toBe('uuid-abc-123');
});

it('round-trips every op type', function (OpType $type): void {
    $framer = new TransportFramer;

    $decoded = $framer->decode($framer->encode([framerEntry(['opType' => $type])]));

    expect($decoded[0]->opType)->toBe($type);
})->with([
    'set' => [OpType::Set],
    'delete' => [OpType::DeleteTombstone],
    'create' => [OpType::CreateRow],
]);

it('leaves unicode unescaped so a frame stays byte-comparable', function (): void {
    $framer = new TransportFramer;

    $frame = $framer->encode([framerEntry(['value' => 'Café — naïve'])]);

    expect($frame)->toContain('Café — naïve')
        ->and($framer->decode($frame)[0]->value)->toBe('Café — naïve');
});

it('refuses a frame too short to hold a length prefix', function (): void {
    (new TransportFramer)->decode('abc');
})->throws(UnexpectedValueException::class, 'frame too short');

it('refuses a frame whose declared length does not match what arrived', function (): void {
    $framer = new TransportFramer;
    $frame = $framer->encode([framerEntry()]);

    // A truncated frame is the ordinary shape of a dropped connection. Trusting
    // the header here would hand json_decode a half-object.
    $framer->decode(substr($frame, 0, strlen($frame) - 5));
})->throws(UnexpectedValueException::class, 'frame length mismatch');

it('refuses a header claiming more than the payload ceiling', function (): void {
    // A hostile peer can put any uint32 here; allocating on the strength of it
    // is how a length prefix becomes a memory exhaustion bug.
    $framer = new TransportFramer;

    $framer->decode(pack('V', 70000).'{}');
})->throws(UnexpectedValueException::class, 'exceeds maximum');

it('refuses a payload that is not JSON', function (): void {
    $payload = 'not json at all';

    (new TransportFramer)->decode(pack('V', strlen($payload)).$payload);
})->throws(UnexpectedValueException::class, 'invalid JSON payload');

it('refuses an op missing a field the replayer depends on', function (string $missing): void {
    $row = [
        'table' => 'transactions', 'pk' => 42, 'field' => 'amount_minor', 'value' => '1000',
        'hlc_l' => 1, 'hlc_c' => 0, 'device_id' => 'device-a', 'op_type' => 'set',
        'signature' => 'sig', 'user_id' => 1,
    ];
    unset($row[$missing]);
    $payload = (string) json_encode([$row]);

    (new TransportFramer)->decode(pack('V', strlen($payload)).$payload);
})->with(['table', 'pk', 'field', 'hlc_l', 'hlc_c', 'device_id', 'op_type', 'signature', 'user_id'])
    ->throws(UnexpectedValueException::class, 'missing required field');

it('refuses an op type it does not know how to replay', function (): void {
    // Accepting this would hand the replayer a case it has no arm for, which
    // is a silent no-op rather than a rejected frame.
    $payload = (string) json_encode([[
        'table' => 'transactions', 'pk' => 42, 'field' => 'amount_minor', 'value' => '1000',
        'hlc_l' => 1, 'hlc_c' => 0, 'device_id' => 'device-a', 'op_type' => 'obliterate',
        'signature' => 'sig', 'user_id' => 1,
    ]]);

    (new TransportFramer)->decode(pack('V', strlen($payload)).$payload);
})->throws(UnexpectedValueException::class, 'unknown op_type');

it('refuses a primary key that is neither int nor string', function (): void {
    $payload = (string) json_encode([[
        'table' => 'transactions', 'pk' => ['nested'], 'field' => 'amount_minor', 'value' => '1000',
        'hlc_l' => 1, 'hlc_c' => 0, 'device_id' => 'device-a', 'op_type' => 'set',
        'signature' => 'sig', 'user_id' => 1,
    ]]);

    (new TransportFramer)->decode(pack('V', strlen($payload)).$payload);
})->throws(UnexpectedValueException::class, 'pk must be int or string');

it('refuses to encode more ops than one frame may carry', function (): void {
    $entries = array_fill(0, 1025, framerEntry());

    (new TransportFramer)->encode($entries);
})->throws(InvalidArgumentException::class, 'too many ops');

it('refuses to decode more ops than one frame may carry', function (): void {
    $row = [
        'table' => 't', 'pk' => 1, 'field' => 'f', 'value' => null,
        'hlc_l' => 1, 'hlc_c' => 0, 'device_id' => 'd', 'op_type' => 'set',
        'signature' => 's', 'user_id' => 1,
    ];
    $payload = (string) json_encode(array_fill(0, 1025, $row));

    // The encode cap means nothing on its own — the peer is the one sending.
    (new TransportFramer)->decode(pack('V', strlen($payload)).$payload);
})->throws(UnexpectedValueException::class);

it('encodes an empty batch as a valid, empty frame', function (): void {
    $framer = new TransportFramer;

    expect($framer->decode($framer->encode([])))->toBe([]);
});
