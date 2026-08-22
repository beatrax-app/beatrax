<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\NativeBridge;
use Modules\Sync\Internal\Transport\Discovery\NativePhpBridge;

// The Jump dev relay ships a stand-in that answers "yes" to every capability and
// then dials a TCP bridge that is not there. So "is this function available" can
// never be asked of the stub alone — the runtime check has to come first, and
// this pins that order.

beforeEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

it('is the bridge contract the discovery stack depends on', function (): void {
    expect(new NativePhpBridge)->toBeInstanceOf(NativeBridge::class);
});

// Asked of the runtime before the capability, so a stub that claims everything
// still gets no call. Off a device this is every environment the suite runs in.
it('claims no support off a mobile runtime', function (): void {
    expect((new NativePhpBridge)->supports('browseBonjour'))->toBeFalse();
});

// The consequence of the above, and the half that matters: an unsupported
// function must read as "nothing came back", never as a dialled bridge.
it('returns nothing from call() rather than dialling a bridge that is absent', function (): void {
    expect((new NativePhpBridge)->call('browseBonjour', ['type' => '_beatrax._tcp']))->toBeNull();
});

/**
 * Reached by reflection because the runtime guard above makes it unreachable
 * anywhere the suite runs: on a device this is the only thing standing between
 * a C extension's answer and an array the caller iterates.
 */
function nativeBridgeDecode(mixed $answer): ?array
{
    $decode = new ReflectionMethod(NativePhpBridge::class, 'decode');

    /** @var array<mixed>|null $result */
    $result = $decode->invoke(null, $answer);

    return $result;
}

// Typed mixed on purpose: off the stub the answer comes from a C extension, and
// a non-string is the case the caller reads as "nothing readable" rather than
// as a TypeError thrown out of a discovery sweep.
it('reads a JSON object back and answers null for everything else', function (): void {
    expect(nativeBridgeDecode('{"peers":[]}'))->toBe(['peers' => []])
        ->and(nativeBridgeDecode('[]'))->toBe([])
        ->and(nativeBridgeDecode(''))->toBeNull()
        ->and(nativeBridgeDecode(null))->toBeNull()
        ->and(nativeBridgeDecode(42))->toBeNull()
        ->and(nativeBridgeDecode('not json'))->toBeNull()
        ->and(nativeBridgeDecode('"a bare string"'))->toBeNull()
        ->and(nativeBridgeDecode('null'))->toBeNull();
});
