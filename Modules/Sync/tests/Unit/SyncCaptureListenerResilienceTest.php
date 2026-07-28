<?php

declare(strict_types=1);

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Psr\Log\LoggerInterface;

/**
 * What SyncCaptureListener does when it cannot capture.
 *
 * The listener runs inside the request that saved the transaction. If it let
 * an exception escape, a device that is not paired — or one whose sync setup
 * is half-finished — would fail the user's edit rather than simply not
 * replicating it. Capture is best-effort by design, and these tests pin that:
 * every failure has to end in a log line, never a throw.
 */
function capturingLogger(array &$calls): LoggerInterface
{
    return new class($calls) implements LoggerInterface
    {
        /** @param array<int, array{level: string, message: string}> $calls */
        public function __construct(private array &$calls) {}

        public function emergency($message, array $context = []): void
        {
            $this->record('emergency', (string) $message);
        }

        public function alert($message, array $context = []): void
        {
            $this->record('alert', (string) $message);
        }

        public function critical($message, array $context = []): void
        {
            $this->record('critical', (string) $message);
        }

        public function error($message, array $context = []): void
        {
            $this->record('error', (string) $message);
        }

        public function warning($message, array $context = []): void
        {
            $this->record('warning', (string) $message);
        }

        public function notice($message, array $context = []): void
        {
            $this->record('notice', (string) $message);
        }

        public function info($message, array $context = []): void
        {
            $this->record('info', (string) $message);
        }

        public function debug($message, array $context = []): void
        {
            $this->record('debug', (string) $message);
        }

        public function log($level, $message, array $context = []): void
        {
            $this->record((string) $level, (string) $message);
        }

        private function record(string $level, string $message): void
        {
            $this->calls[] = ['level' => $level, 'message' => $message];
        }
    };
}

// A bare container, which is what a device that has not finished pairing
// effectively has: OpLogWriter needs a device id, a user id and key material
// as constructor primitives, so resolving it throws rather than returning a
// half-built writer.
function unresolvableContainer(): Container
{
    return new IlluminateContainer;
}

it('does not fail the user\'s save when the device cannot capture yet', function (): void {
    $calls = [];
    $listener = new SyncCaptureListener(unresolvableContainer(), capturingLogger($calls));

    $listener->handle(new TransactionMutated(
        transactionId: 42,
        userId: 1,
        mutationType: 'edit',
        dirtyFields: ['amount_minor' => 1000],
    ));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['level'])->toBe('error')
        ->and($calls[0]['message'])->toContain('capture failed');
});

it('does not fail a split save either', function (): void {
    $calls = [];
    $listener = new SyncCaptureListener(unresolvableContainer(), capturingLogger($calls));

    $listener->handleSplit(new TransactionSplitMutated(
        splitId: 7,
        transactionId: 42,
        userId: 1,
        mutationType: 'edit',
        dirtyFields: ['amount_minor' => 500],
    ));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['level'])->toBe('error')
        ->and($calls[0]['message'])->toContain('split capture failed');
});

// The unknown-mutationType arm is not asserted here. Reaching it needs a
// resolvable OpLogWriter, and that class is final with twelve constructor
// dependencies — building one would make this a test of the writer's wiring
// rather than of the listener's routing. The Feature suite drives it with a
// real writer already.
