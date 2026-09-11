<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\LocalNetworkGate;
use Modules\Sync\Internal\Transport\Discovery\NativeBridge;
use Modules\Sync\Public\Enums\LocalNetworkAccess;

// Every Beatrax build declares NSLocalNetworkUsageDescription, and reading that
// declaration back would answer Granted on the very device this was measured
// on — where iOS had the prompt outstanding and was dropping every LAN
// connection the app made. The declaration says the app may ASK. Only the shell
// can say what the reader answered.

beforeEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

/**
 * @param  array<string, array<mixed>|null>  $answers  Keyed by bridge function.
 */
function localNetworkBridge(array $answers): NativeBridge
{
    return new class($answers) implements NativeBridge
    {
        /**
         * @param  array<string, array<mixed>|null>  $answers
         */
        public function __construct(private readonly array $answers) {}

        public function supports(string $function): bool
        {
            return array_key_exists($function, $this->answers);
        }

        /**
         * @param  array<string, scalar>  $parameters
         * @return array<mixed>|null
         */
        public function call(string $function, array $parameters): ?array
        {
            return $this->answers[$function] ?? null;
        }
    };
}

it('calls the permission unconfirmed on iOS while no shell can read it back', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $gate = new LocalNetworkGate(localNetworkBridge([]));

    expect($gate->access())->toBe(LocalNetworkAccess::Unconfirmed)
        ->and($gate->access()->mayExplainSilence())->toBeTrue();
});

// The platform read is a fallback, not a verdict about the reader. Where the
// operating system holds no LAN traffic behind a prompt there is nothing to
// confirm, and a screen must not offer a setting that does not exist there.
it('calls it granted wherever no such prompt stands between the app and the network', function (): void {
    $gate = new LocalNetworkGate(localNetworkBridge([]));

    expect($gate->access())->toBe(LocalNetworkAccess::Granted)
        ->and($gate->access()->mayExplainSilence())->toBeFalse();
});

it('takes the shell answer over the platform guess, in both directions', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $granted = new LocalNetworkGate(localNetworkBridge([
        LocalNetworkGate::STATUS_FUNCTION => ['granted' => true],
    ]));

    $refused = new LocalNetworkGate(localNetworkBridge([
        LocalNetworkGate::STATUS_FUNCTION => ['granted' => false],
    ]));

    expect($granted->access())->toBe(LocalNetworkAccess::Granted)
        ->and($refused->access())->toBe(LocalNetworkAccess::Unconfirmed);
});

// The same envelope rule the browse follows: a failure carries `status`, and
// reading one as an answer would let a refusal pass for a grant.
it('reads a refusal envelope as no answer rather than as a grant', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $gate = new LocalNetworkGate(localNetworkBridge([
        LocalNetworkGate::STATUS_FUNCTION => ['status' => 'error', 'granted' => true],
    ]));

    expect($gate->access())->toBe(LocalNetworkAccess::Unconfirmed);
});

it('reports no reader was asked where the shell registers no way to ask', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $gate = new LocalNetworkGate(localNetworkBridge([]));

    expect($gate->askTheReader())->toBeFalse();
});

it('puts the question to the reader through the shell where one offers it', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $gate = new LocalNetworkGate(localNetworkBridge([
        LocalNetworkGate::REQUEST_FUNCTION => ['asked' => true],
    ]));

    expect($gate->askTheReader())->toBeTrue();
});
