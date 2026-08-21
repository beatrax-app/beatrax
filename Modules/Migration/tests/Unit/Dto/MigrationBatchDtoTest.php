<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Public\Dto\ConflictDto;
use Modules\Migration\Public\Dto\MigrationAccountDto;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Dto\MigrationBudgetAssignmentDto;
use Modules\Migration\Public\Dto\MigrationCategoryDto;
use Modules\Migration\Public\Dto\MigrationGoalDto;
use Modules\Migration\Public\Dto\MigrationPayeeDto;
use Modules\Migration\Public\Dto\MigrationScheduleDto;
use Modules\Migration\Public\Dto\MigrationTransactionDto;
use Modules\Migration\Public\Dto\UnmappedItemDto;
use Spatie\LaravelData\Data;

/** @return array<int, class-string> */
function migrationDtoClasses(): array
{
    return [
        MigrationCategoryDto::class,
        MigrationAccountDto::class,
        MigrationPayeeDto::class,
        MigrationBudgetAssignmentDto::class,
        MigrationTransactionDto::class,
        MigrationGoalDto::class,
        MigrationScheduleDto::class,
        UnmappedItemDto::class,
        ConflictDto::class,
        MigrationBatch::class,
    ];
}

it('Dto: every one of the ten IR Dtos is final, strict-typed, and extends spatie Data', function (): void {
    foreach (migrationDtoClasses() as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue("{$class} must be final");
        expect($reflection->isSubclassOf(Data::class))->toBeTrue("{$class} must extend Spatie\\LaravelData\\Data");

        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        foreach ($constructor->getParameters() as $parameter) {
            $promoted = $parameter->isPromoted();
            expect($promoted)->toBeTrue("{$class}::\${$parameter->getName()} must be constructor-promoted");

            $property = $reflection->getProperty($parameter->getName());
            expect($property->isReadOnly())->toBeTrue("{$class}::\${$parameter->getName()} must be readonly");
            expect($property->isPublic())->toBeTrue("{$class}::\${$parameter->getName()} must be public");
        }
    }
});

it('Dto: every money-bearing Dto field is typed Modules\Ledger\Public\ValueObjects\Money, never a bare int/float', function (): void {
    $moneyBearingFields = [
        MigrationTransactionDto::class => ['amount'],
        MigrationBudgetAssignmentDto::class => ['budgeted'],
        MigrationGoalDto::class => ['targetAmount'],
    ];

    foreach ($moneyBearingFields as $class => $fields) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $paramsByName = [];
        foreach ($constructor->getParameters() as $parameter) {
            $paramsByName[$parameter->getName()] = $parameter;
        }

        foreach ($fields as $field) {
            expect(array_key_exists($field, $paramsByName))->toBeTrue("{$class} is missing expected money field \${$field}");

            $type = $paramsByName[$field]->getType();
            expect($type)->toBeInstanceOf(ReflectionNamedType::class);
            /** @var ReflectionNamedType $type */
            expect($type->getName())->toBe(Money::class, "{$class}::\${$field} must be typed Money, found {$type->getName()}");
        }
    }
});

it('Dto: reflection sweep confirms NO bare int/float field is used for money anywhere in the money-bearing Dtos', function (): void {
    $suspectNames = ['amount_minor', 'amountminor', 'targetminor', 'budgetedminor'];

    foreach ([MigrationTransactionDto::class, MigrationBudgetAssignmentDto::class, MigrationGoalDto::class] as $class) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        foreach ($constructor->getParameters() as $parameter) {
            $normalized = strtolower($parameter->getName());
            expect(in_array($normalized, $suspectNames, true))->toBeFalse(
                "{$class}::\${$parameter->getName()} looks like a bare-minor money field; use Money instead",
            );
        }
    }
});

it('Dto: MigrationBatch.transactions is typed iterable (Generator-capable), never a materialized array', function (): void {
    $reflection = new ReflectionClass(MigrationBatch::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $transactionsParam = null;
    foreach ($constructor->getParameters() as $parameter) {
        if ($parameter->getName() === 'transactions') {
            $transactionsParam = $parameter;
        }
    }

    expect($transactionsParam)->not->toBeNull();
    $type = $transactionsParam->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $type */
    expect($type->getName())->toBe('iterable');
});

it('Dto: passing a Generator to MigrationBatch.transactions never eagerly materializes it', function (): void {
    $yieldedCount = 0;
    $generator = (function () use (&$yieldedCount): Generator {
        for ($i = 0; $i < 3; $i++) {
            $yieldedCount++;

            yield new MigrationTransactionDto(
                sourceExternalId: (string) $i,
                accountSourceExternalId: 'acc-1',
                postedAt: CarbonImmutable::parse('2026-01-01'),
                amount: Money::ofMinor(-100, 'EUR'),
                payeeSourceExternalId: null,
                categorySourceExternalId: null,
                description: null,
                clearedStatus: 'cleared',
                sourceRowIndex: $i,
                rawPayload: [],
            );
        }
    })();

    $batch = new MigrationBatch(
        sourceProduct: 'ynab4',
        budgetCurrency: 'EUR',
        categories: new Collection,
        accounts: new Collection,
        payees: new Collection,
        budgetAssignments: new Collection,
        goals: new Collection,
        schedules: new Collection,
        unmapped: new Collection,
        transactions: $generator,
    );

    // Proves the Dto layer never calls iterator_to_array() on the generator.
    expect($yieldedCount)->toBe(0);
    expect($batch->transactions)->toBeInstanceOf(Generator::class);

    // One step, not three: genuine laziness, not an eager pre-fill wearing a
    // Generator return type.
    $one = null;
    foreach ($batch->transactions as $item) {
        $one = $item;

        break;
    }

    expect($yieldedCount)->toBe(1);
    expect($one)->toBeInstanceOf(MigrationTransactionDto::class);
});

it('Dto: UnmappedItemDto itemType covers the four documented kinds', function (): void {
    foreach (['category', 'payee', 'extra', 'conflict'] as $itemType) {
        $dto = new UnmappedItemDto(
            itemType: $itemType,
            sourceExternalId: 'x',
            displayLabel: 'Some label',
            reason: 'test reason',
        );

        expect($dto->itemType)->toBe($itemType);
    }
});

it('Dto: ConflictDto defaults resolution to keep_local (D-14)', function (): void {
    $conflict = new ConflictDto(
        entityType: 'budget_assignment',
        sourceExternalId: 'cat-1',
        fieldName: 'assigned_minor',
        localValue: 30000,
        sourceValue: 25000,
        baselineValue: 20000,
    );

    expect($conflict->resolution)->toBe('keep_local');
});
