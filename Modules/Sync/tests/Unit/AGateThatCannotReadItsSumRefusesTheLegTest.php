<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Sync\Internal\Merge\SplitOverfillGate;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// The split-sum gate reads two amounts and compares them. Both reads used to
// fold their own failure into a number — a missing parent into null, an
// unreadable sum into zero — so a database that could not answer read exactly
// like a transaction whose legs still had room. The peer's second set then
// landed on a transaction already split, and quarantine stayed empty.
/**
 * @return array<string, mixed>
 */
function unreadableSumLegPayload(): array
{
    return [
        'user_id' => 1,
        'transaction_id' => 7,
        'category_id' => 3,
        'settled_amount_minor' => -4000,
        'settled_currency' => 'EUR',
        'split_uuid' => 'leg-under-an-unreadable-sum',
    ];
}

function unreadableSumDatabase(): DatabaseManager
{
    return new class extends DatabaseManager
    {
        public function __construct()
        {
            // Nothing to set up: this manager answers one way and never
            // reaches the connections the parent would build.
        }

        /**
         * @param  string|null  $name
         */
        public function connection($name = null): never
        {
            throw new QueryException(
                'sqlite',
                'select "settled_amount_minor" from "transactions"',
                [],
                new RuntimeException('database is locked'),
            );
        }
    };
}

it('refuses a leg whose sum it could not take instead of admitting it', function (): void {
    $gate = new SplitOverfillGate(unreadableSumDatabase());

    expect($gate->reasonToRefuse('transaction_splits', 'leg-1', unreadableSumLegPayload()))
        ->toBe(QuarantineReason::SplitSumUnreadable);
});

// The reason has to be one a later pass takes again: the read is the only
// thing that failed, so retiring the entry would discard a leg over a lock.
it('names a reason the reprojector replays', function (): void {
    expect(QuarantineReason::recoverable())->toContain(QuarantineReason::SplitSumUnreadable->value)
        ->and(QuarantineReason::keyRecoverable())->not->toContain(QuarantineReason::SplitSumUnreadable->value);
});
