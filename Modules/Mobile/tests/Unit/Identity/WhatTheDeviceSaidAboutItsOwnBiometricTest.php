<?php

declare(strict_types=1);

use Beatrax\BiometricVault\BiometricVault;

// The answer crosses the bridge as JSON and every reduction of it on the way
// back is a chance to turn "the device refused, and here is which refusal" into
// the word false. These are the shapes the bridge can produce.

// Anonymous on purpose, as everywhere else in this suite: a named subclass
// would be resolved when the file is COMPILED, and the native vault package is
// autoloaded only from the mobile-app root, so the repo-rooted run would fatal
// rather than skip.
function vaultAnswering(string $reply): BiometricVault
{
    return new class($reply) extends BiometricVault
    {
        public function __construct(private readonly string $reply) {}

        protected function bridge(string $function, string $payload): ?string
        {
            return $function === 'BiometricVault.IsAvailable' ? $this->reply : '';
        }
    };
}

it('reads what the device said about itself', function (string $reply, bool $available, string $reason): void {
    expect(vaultAnswering($reply)->capability())->toBe(['available' => $available, 'reason' => $reason]);
})->with([
    'an iPhone with Face ID enrolled' => ['{"available":true,"reason":"available"}', true, 'available'],
    'a Samsung with no finger enrolled' => ['{"available":false,"reason":"none_enrolled"}', false, 'none_enrolled'],
    'Android, where Set is still a skeleton' => ['{"available":true,"reason":"async_unimplemented"}', true, 'async_unimplemented'],
    // Not "available": a missing key is not a yes, and a bridge that answered
    // an object with nothing in it has told us nothing about the enclave.
    'an object with neither key in it' => ['{}', false, 'unreadable'],
    'the bridge refusing the call' => ['{"status":"error","message":"function not found"}', false, 'unreadable'],
    'nothing at all' => ['', false, 'unreadable'],
    'a reason that is not a string' => ['{"available":false,"reason":7}', false, 'unreadable'],
    // A truthy-but-not-true availability is a decoding accident, never a yes.
    'available as a string' => ['{"available":"true","reason":"available"}', false, 'available'],
])->skip(
    fn (): bool => ! class_exists(BiometricVault::class),
    'The native vault package is autoloaded only from the mobile-app root.',
);

// The refusal reason from the previous call must not be read as this one's:
// lastError() is cleared at the top of every bridge call, and a capability
// probe that inherited an enrolment failure would name the wrong operation.
it('does not carry a refusal over from the call before it', function (): void {
    $vault = vaultAnswering('{"available":true,"reason":"available"}');

    $vault->set('slot', 'value');

    expect($vault->lastError())->not->toBeNull()
        ->and($vault->capability()['available'])->toBeTrue()
        ->and($vault->lastError())->toBeNull();
})->skip(
    fn (): bool => ! class_exists(BiometricVault::class),
    'The native vault package is autoloaded only from the mobile-app root.',
);
