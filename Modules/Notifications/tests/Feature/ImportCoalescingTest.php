<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// Dispatch runs inside suppressDelivery() so no case here ever attempts a real
// OS or mobile notification.

function icImportUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  list<string>  $sourceFormats
 */
function icDispatchBatch(User $user, int $insertedCount, array $sourceFormats): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $insertedCount, $sourceFormats): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new TransactionBatchImported(
            userId: $user->id,
            insertedCount: $insertedCount,
            sourceFormats: $sourceFormats,
        ));
    });
}

function icNotificationCount(int $userId, string $triggerType): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', $triggerType)
        ->count();
}

function icNotificationBody(int $userId, string $triggerType): ?string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var string|null $body */
    $body = $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', $triggerType)
        ->value('body');

    return $body;
}

it('produces EXACTLY 1 import_finished row for a 500-row batch, not 500', function (): void {
    $user = icImportUser('ic-500-row');

    icDispatchBatch($user, 500, ['csv']);

    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(1);

    $body = icNotificationBody($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED);
    expect($body)->toContain('500');
});

it('produces exactly 1 receipts_found row for a 500-row all-eml batch', function (): void {
    $user = icImportUser('ic-500-eml');

    icDispatchBatch($user, 500, ['eml']);

    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND))->toBe(1);
    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(0);
});

it('resolves a mixed csv + eml batch to import_finished, not receipts_found', function (): void {
    $user = icImportUser('ic-mixed-format');

    icDispatchBatch($user, 12, ['csv', 'eml']);

    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(1);
    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND))->toBe(0);
});

it('reads "1 transaction" (singular) for a 1-row batch, not "1 transactions"', function (): void {
    $user = icImportUser('ic-singular');

    icDispatchBatch($user, 1, ['csv']);

    $body = icNotificationBody($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED);
    expect($body)->toContain('1 transaction imported');
    expect($body)->not->toContain('1 transactions');
});

it('produces two rows for two same-second imports with DIFFERENT counts (occurrence is per-batch, not per-day)', function (): void {
    CarbonImmutable::setTestNow('2026-07-18 12:00:00');

    $user = icImportUser('ic-two-imports-same-second');

    icDispatchBatch($user, 10, ['csv']);
    icDispatchBatch($user, 20, ['csv']);

    expect(icNotificationCount($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(2);

    CarbonImmutable::setTestNow();
});

it('never leaks user A\'s import into user B\'s inbox (cross-user)', function (): void {
    $userA = icImportUser('ic-cross-a');
    $userB = icImportUser('ic-cross-b');

    icDispatchBatch($userA, 50, ['csv']);

    expect(icNotificationCount($userA->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(1);
    expect(icNotificationCount($userB->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(0);
});
