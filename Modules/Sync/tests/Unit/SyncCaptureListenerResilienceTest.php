<?php

declare(strict_types=1);

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Psr\Log\LoggerInterface;

// The listener runs inside the request that saved the transaction, so letting an
// exception escape would fail the user's edit on a device that is merely unpaired.
// Which line it logs matters too: "sync is off" is the resting state of most
// installs, and at error level it once filled a real log with 120k entries.
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
        ->and($calls[0]['level'])->toBe('debug')
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
        ->and($calls[0]['level'])->toBe('debug')
        ->and($calls[0]['message'])->toContain('split capture failed');
});

// The other half of the contract: quiet about a device that simply has no
// writer, loud about a writer that has one and still could not use it.
it('still reports a genuine capture failure at error level', function (): void {
    $calls = [];
    $container = new IlluminateContainer;
    $container->bind(OpLogWriter::class, function (): OpLogWriter {
        throw new RuntimeException('the op-log write itself failed');
    });

    $listener = new SyncCaptureListener($container, capturingLogger($calls));

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

// Reaching the unknown-mutationType arm needs make(OpLogWriter) to succeed, and
// that class is final with twelve dependencies, so the container hands back a
// placeholder. Sound only because that arm never touches the writer: route the
// writer through it and the TypeError becomes an error line the level test fails on.
function placeholderWriterContainer(): Container
{
    $container = new IlluminateContainer;
    $container->bind(OpLogWriter::class, fn (): object => new stdClass);

    return $container;
}

// Every mutating surface routes on a free-form string, and a device running
// an older build can emit a verb this one has never heard of. The listener
// has to treat that as "nothing to replicate" rather than as a failure —
// warn, and let the user's save stand.
it('warns rather than throws on a mutation type it does not recognise', function (Closure $dispatch): void {
    $calls = [];
    $listener = new SyncCaptureListener(placeholderWriterContainer(), capturingLogger($calls));

    $dispatch($listener);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['level'])->toBe('warning')
        ->and($calls[0]['message'])->toBe('SyncCaptureListener: unknown mutationType');
})->with([
    'transaction' => [fn (SyncCaptureListener $l) => $l->handle(
        new TransactionMutated(transactionId: 42, userId: 1, mutationType: 'teleport'),
    )],
    'split' => [fn (SyncCaptureListener $l) => $l->handleSplit(
        new TransactionSplitMutated(splitId: 7, transactionId: 42, userId: 1, mutationType: 'teleport'),
    )],
    'envelope assignment' => [fn (SyncCaptureListener $l) => $l->handleEnvelopeAssignment(
        new EnvelopeAssignmentMutated(assignmentId: 3, userId: 1, mutationType: 'teleport'),
    )],
    'envelope move' => [fn (SyncCaptureListener $l) => $l->handleEnvelopeMove(
        new EnvelopeMoveMutated(moveId: 4, userId: 1, mutationType: 'teleport'),
    )],
    'envelope setting' => [fn (SyncCaptureListener $l) => $l->handleEnvelopeSetting(
        new EnvelopeSettingMutated(settingId: 5, userId: 1, mutationType: 'teleport'),
    )],
    'saved report' => [fn (SyncCaptureListener $l) => $l->handleSavedReport(
        new SavedReportMutated(reportId: 6, userId: 1, mutationType: 'teleport'),
    )],
    'notification' => [fn (SyncCaptureListener $l) => $l->handleNotificationMutated(
        new NotificationMutated(notificationId: 'n-1', userId: 1, mutationType: 'teleport'),
    )],
    'notification preference' => [fn (SyncCaptureListener $l) => $l->handleNotificationPreferenceMutated(
        new NotificationPreferenceMutated(preferenceId: 8, userId: 1, mutationType: 'teleport'),
    )],
]);
