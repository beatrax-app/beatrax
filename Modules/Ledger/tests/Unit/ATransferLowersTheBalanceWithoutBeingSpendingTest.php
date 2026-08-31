<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;

it('keeps every direction where it was, because signs and totals depend on it', function (TransactionType $type, Direction $direction): void {
    expect($type->direction())->toBe($direction);
})->with([
    'an expense lowers the balance' => [TransactionType::Expense, Direction::Expense],
    'a transfer out lowers it too' => [TransactionType::TransferOut, Direction::Expense],
    'a fee lowers it' => [TransactionType::Fee, Direction::Expense],
    'an adjustment is carried on the expense side' => [TransactionType::Adjustment, Direction::Expense],
    'income raises it' => [TransactionType::Income, Direction::Income],
    'a transfer in raises it' => [TransactionType::TransferIn, Direction::Income],
    'a refund raises it' => [TransactionType::Refund, Direction::Income],
]);

it('separates money the reader moved between their own accounts from money they spent', function (TransactionType $type, bool $external): void {
    expect($type->isExternalMovement())->toBe($external);
})->with([
    'an expense is spending' => [TransactionType::Expense, true],
    'income is earning' => [TransactionType::Income, true],
    'a fee is charged by someone' => [TransactionType::Fee, true],
    'a refund is paid back by someone' => [TransactionType::Refund, true],
    'a transfer out is the reader moving their own money' => [TransactionType::TransferOut, false],
    'a transfer in is the other half of that move' => [TransactionType::TransferIn, false],
    'an adjustment reconciles against nobody' => [TransactionType::Adjustment, false],
]);

it('makes every case answer, so a type added later cannot join the scored set unnoticed', function (): void {
    $answered = [];
    foreach (TransactionType::cases() as $type) {
        $answered[$type->value] = $type->isExternalMovement();
    }

    expect(array_keys($answered))->toBe(array_column(TransactionType::cases(), 'value'));
});

it('scopes a direction to the types that are the reader\'s own doing', function (Direction $direction, string $first, string $second): void {
    expect(TransactionType::externalMovementValuesFor($direction))->toBe([$first, $second]);
})->with([
    'expense side drops the transfer leg and the adjustment' => [Direction::Expense, 'expense', 'fee'],
    'income side drops the other transfer leg' => [Direction::Income, 'income', 'refund'],
]);

it('judges an unreadable type exactly as it was judged before the predicate existed', function (mixed $type): void {
    expect(TransactionType::isExternalMovementOf($type))->toBeTrue();
})->with([
    'a type outside the set' => ['not_a_transaction_type'],
    'a missing type' => [null],
    'a type that is not even a string' => [17],
]);

it('reads the same answer off a row value as off the case', function (): void {
    expect(TransactionType::isExternalMovementOf(TransactionType::TransferOut->value))->toBeFalse()
        ->and(TransactionType::isExternalMovementOf(TransactionType::Expense->value))->toBeTrue();
});
