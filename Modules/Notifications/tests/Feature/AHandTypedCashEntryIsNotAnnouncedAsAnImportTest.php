<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// Driven through the cash book's own screen rather than a hand-built event: the
// row that reached the phone was written by whatever RecordManualTransaction
// hands the ledger, and a fixture dispatching TransactionBatchImported itself
// would decide the very payload under test.

function htceUser(): User
{
    return User::query()->create([
        'username' => 'htce-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  callable(): void  $act
 */
function htceUndelivered(callable $act): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery($act);
}

function htceAddCashEntry(User $user, string $counterparty, string $amount): void
{
    htceUndelivered(function () use ($user, $counterparty, $amount): void {
        Livewire::actingAs($user)
            ->test(CashBookPage::class)
            ->set('amount', $amount)
            ->set('date', '2026-05-17')
            ->set('counterparty', $counterparty)
            ->call('add')
            ->assertSet('error', '');
    });
}

function htceRow(User $user): NotificationDto
{
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);

    /** @var DeepLinkResolver $deepLinks */
    $deepLinks = app(DeepLinkResolver::class);

    $rows = $query->unreadForUser($user)['rows'];
    expect($rows)->toHaveCount(1);

    return $deepLinks->resolve($rows[0], $user);
}

it('does not tell a reader their own typing was an import', function (): void {
    $user = htceUser();

    htceAddCashEntry($user, 'Marktkraam Zaandam', '12,75');

    $row = htceRow($user);

    expect($row->title)->not->toContain('Import')
        ->and($row->body)->not->toContain('imported')
        ->and($row->typeWord)->not->toBe('Import');
});

it('names the cash book, in the singular, for one hand-typed entry', function (): void {
    $user = htceUser();

    htceAddCashEntry($user, 'Marktkraam Zaandam', '12,75');

    $row = htceRow($user);

    expect($row->triggerType)->toBe(NotificationTrigger::ManualEntryRecorded->value)
        ->and($row->title)->toBe('Cash book updated')
        ->and($row->body)->toBe('1 entry added by hand.')
        ->and($row->typeWord)->toBe('Cash');
});

it('sends the reader back to the cash book, not to the import screen', function (): void {
    $user = htceUser();

    htceAddCashEntry($user, 'Marktkraam Zaandam', '12,75');

    $row = htceRow($user);

    expect($row->deepLinkDisabled)->toBeFalse()
        ->and($row->deepLinkUrl)->toBe(Destination::CashBook->url());
});

it('still calls a parsed statement batch an import', function (): void {
    $user = htceUser();

    htceUndelivered(function () use ($user): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new TransactionBatchImported(
            userId: $user->id,
            insertedCount: 4,
            sourceFormats: ['camt053'],
        ));
    });

    $row = htceRow($user);

    expect($row->triggerType)->toBe(NotificationTrigger::ImportFinished->value)
        ->and($row->title)->toBe('Import finished')
        ->and($row->body)->toBe('4 transactions imported.')
        ->and($row->typeWord)->toBe('Import');
});
