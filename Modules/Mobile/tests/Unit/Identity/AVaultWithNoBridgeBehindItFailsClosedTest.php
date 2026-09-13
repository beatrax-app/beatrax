<?php

declare(strict_types=1);

// The seam below runtimeAvailable(): the repo root does not autoload the
// native plugin, so every class_exists() guard in the vault is false here and
// each one had gone unexecuted. What they answer decides whether an absent
// bridge reads as "nothing enrolled" — the one answer that makes the lock
// screen erase the enrolment record.

use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @return LoggerInterface&object{lines: list<array{0: string, 1: string, 2: array<string, mixed>}>}
 */
function recordingLog(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
        public array $lines = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [(string) $level, (string) $message, $context];
        }
    };
}

// Pins only the two answers a device gives about itself and leaves every
// bridge call real, so the guards under test are the ones that run.
function bridgelessVault(LoggerInterface $log, bool $runtime = true, ?bool $canStore = true): BiometricKeyVault
{
    return new class(app(BiometricKeyBlobCodec::class), $log, $runtime, $canStore) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly bool $runtime,
            private readonly ?bool $canStore,
        ) {
            parent::__construct($codec, $log);
        }

        protected function runtimeAvailable(): bool
        {
            return $this->runtime;
        }

        // null hands the question back to the real probe, which asks a bridge
        // that is not there.
        protected function platformCanStore(): bool
        {
            return $this->canStore ?? parent::platformCanStore();
        }
    };
}

it('reads a bridge that is not there as a device that cannot store', function (): void {
    $log = recordingLog();

    expect(bridgelessVault($log, canStore: null)->isAvailable())->toBeFalse();

    expect($log->lines)->toHaveCount(1);
    expect($log->lines[0][0])->toBe('debug');
    expect($log->lines[0][2]['reason'] ?? null)->toBe('unreadable');
});

it('refuses to enrol and says the native side gave no reason', function (): void {
    $log = recordingLog();

    expect(bridgelessVault($log)->enroll(7, str_repeat("\x01", 32)))->toBeFalse();

    expect($log->lines)->toHaveCount(1);
    expect($log->lines[0][0])->toBe('warning');
    expect($log->lines[0][1])->toContain('refused to store');
    expect($log->lines[0][2]['reason'] ?? null)->toBe('the native side gave none');
});

it('calls a bridge that never answered a failure, never nothing enrolled', function (): void {
    $log = recordingLog();

    // MISSING here would take the lock screen down the branch that marks the
    // cold-start enrolment gone and tells the reader to set it up again.
    expect(bridgelessVault($log)->recover(7)->status)->toBe(BiometricRecoverResult::FAILED);
    expect($log->lines)->toBe([]);
});

it('has nothing pending to complete when the bridge is gone', function (): void {
    $log = recordingLog();

    expect(bridgelessVault($log)->completePendingRecover(7)->status)->toBe(BiometricRecoverResult::MISSING);
});

it('reports the entry as still held when the removal cannot be made', function (): void {
    $log = recordingLog();

    expect(bridgelessVault($log)->clear(7))->toBeFalse();

    expect($log->lines)->toHaveCount(1);
    expect($log->lines[0][0])->toBe('warning');
    expect($log->lines[0][1])->toContain('refused to remove');
    expect($log->lines[0][2]['reason'] ?? null)->toBe('the native side gave none');
});

it('reports nothing left to clear where there is nowhere to hold a key', function (): void {
    $log = recordingLog();

    expect(bridgelessVault($log, runtime: false)->clear(7))->toBeTrue();
    expect($log->lines)->toBe([]);
});

it('stands a ceremony down without reaching for a bridge that is not there', function (): void {
    $log = recordingLog();

    $vault = bridgelessVault($log);
    $vault->cancelPrompt();

    expect($log->lines)->toBe([]);
});
