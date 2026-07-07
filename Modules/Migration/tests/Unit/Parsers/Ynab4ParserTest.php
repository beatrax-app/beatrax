<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the Ynab4Parser contract
 * (Req 1/2/3/4/5/6/7). Ynab4Parser does not exist until Plan 03 — every
 * test below is EXPECTED to fail now (missing-class error), not pass. This
 * file defines what "done" means for Plan 03.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'ynab4-parser-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('Ynab4Parser: format() returns ynab4', function (): void {
    $parser = app(Ynab4Parser::class);

    expect($parser)->toBeInstanceOf(ParsesMigrationSource::class);
    expect($parser->format())->toBe('ynab4');
});

it('Ynab4Parser: parses the v1 golden fixture into a populated MigrationBatch — Req 1/2/3/4/5/6/7', function (): void {
    $parser = app(Ynab4Parser::class);

    $batch = $parser->parse(MigrationFixturePaths::ynab4Dir('v1'), $this->user, 1);

    expect($batch)->toBeInstanceOf(MigrationBatch::class);
    expect($batch->sourceProduct)->toBe('ynab4');

    // Req 2: categories non-empty (Groceries, Household, Salary).
    expect($batch->categories)->not->toBeEmpty();
    expect($batch->categories->pluck('name')->all())->toContain('Groceries', 'Household', 'Salary');

    // Accounts non-empty (Checking, Savings).
    expect($batch->accounts)->not->toBeEmpty();
    expect($batch->accounts->pluck('name')->all())->toContain('Checking', 'Savings');

    // Req 6: real payees resolved, transfer payees excluded.
    expect($batch->payees)->not->toBeEmpty();
    $payeeNames = $batch->payees->pluck('name')->all();
    expect($payeeNames)->toContain('Albert Heijn', 'Employer', 'Supermarket');
    expect($payeeNames)->not->toContain('Transfer : Savings', 'Transfer : Checking');

    // Req 3: budget grid — 2 months x 2 categories = 4 assignment rows.
    expect($batch->budgetAssignments)->toHaveCount(4);

    // Req 4/5: transactions generator yields exactly 6 entries — TWO plain
    // expenses from the same payee (Albert Heijn — Req 6 needs a real
    // multi-transaction payee), one plain income, ONE split-parent (2
    // legs), and a transfer PAIR (2 entries) — never flattened split legs
    // or a synthesized parent-plus-legs count of 7+.
    $transactions = iterator_to_array($batch->transactions);
    expect($transactions)->toHaveCount(6);

    $splitParents = array_values(array_filter($transactions, static fn ($t) => $t->splits !== []));
    expect($splitParents)->toHaveCount(1);
    expect($splitParents[0]->splits)->toHaveCount(2);
    $legSum = array_sum(array_map(static fn (array $leg) => $leg['amount']->toMinor(), $splitParents[0]->splits));
    expect($legSum)->toBe($splitParents[0]->amount->toMinor());

    $transferLegs = array_values(array_filter($transactions, static fn ($t) => $t->transferCounterpartSourceExternalId !== null));
    expect($transferLegs)->toHaveCount(2);
    expect(abs($transferLegs[0]->amount->toMinor()))->toBe(abs($transferLegs[1]->amount->toMinor()));

    // Req 6: Albert Heijn appears on exactly 2 distinct transactions.
    $albertHeijnPayeeId = $batch->payees->first(static fn ($p) => $p->name === 'Albert Heijn')?->sourceExternalId;
    expect($albertHeijnPayeeId)->not->toBeNull();
    $albertHeijnTxCount = count(array_filter($transactions, static fn ($t) => $t->payeeSourceExternalId === $albertHeijnPayeeId));
    expect($albertHeijnTxCount)->toBe(2);

    // Req 4 (cleared/uncleared): exactly 1 uncleared (the income row, 'N'),
    // the rest cleared — YNAB4's Cleared column never yields 'reconciled'.
    $clearedCounts = array_count_values(array_map(static fn ($t) => $t->clearedStatus, $transactions));
    expect($clearedCounts['uncleared'] ?? 0)->toBe(1);
    expect($clearedCounts['reconciled'] ?? 0)->toBe(0);
});

it('Ynab4Parser: rejects the corrupt fixture with UnrecognizedMigrationFileException — Req 1 reject-not-partial', function (): void {
    $parser = app(Ynab4Parser::class);
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());

    expect(fn () => $parser->parse($extracted, $this->user, 1))
        ->toThrow(UnrecognizedMigrationFileException::class);
});

it('WR-07: a negative Budgeted value is preserved, NOT silently coerced to zero', function (): void {
    $dir = sys_get_temp_dir().'/ynab4-negative-budgeted-'.uniqid('', true);
    mkdir($dir, 0755, true);

    file_put_contents(
        $dir.'/Test Register.csv',
        "Account,Flag,\"Check Number\",Date,Payee,\"Master Category\",\"Sub Category\",Memo,Outflow,Inflow,Cleared,\"Running Balance\"\n"
        ."Checking,,,01/15/2026,\"Albert Heijn\",Frequent,Groceries,,45.00,0.00,C,955.00\n",
    );
    file_put_contents(
        $dir.'/Test Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n"
        ."2026-01,Frequent,Groceries,-50.00,45.00,-95.00\n",
    );

    $parser = app(Ynab4Parser::class);
    $batch = $parser->parse($dir, $this->user, 1);

    $assignment = $batch->budgetAssignments->first();
    expect($assignment)->not->toBeNull();
    // A blank/malformed cell would have coerced to 0 either way — the
    // negative value must survive as -50.00 (-5000 minor), not 0.
    expect($assignment->budgeted->toMinor())->toBe(-5000);

    @unlink($dir.'/Test Register.csv');
    @unlink($dir.'/Test Budget.csv');
    @rmdir($dir);
});

it('CR-01: a category group literally named "Group" does not collide with an unrelated group named "Rent" — 13.5-REVIEW-DEEP.md exact example', function (): void {
    $dir = sys_get_temp_dir().'/ynab4-group-key-collision-'.uniqid('', true);
    mkdir($dir, 0755, true);

    file_put_contents(
        $dir.'/Test Register.csv',
        "Account,Flag,\"Check Number\",Date,Payee,\"Master Category\",\"Sub Category\",Memo,Outflow,Inflow,Cleared,\"Running Balance\"\n",
    );
    // Group 1: literally named "Group", containing a leaf category "Rent".
    // Group 2: a genuinely different group named "Rent", containing a leaf
    // category "Something". Before the CR-01 fix, naturalGroupKey('Rent')
    // ('group/rent') collided with naturalCategoryKey('Group', 'Rent')
    // ('group/rent'), so the "Rent" group's own parent row was silently
    // skipped and "Something" resolved its parent to the "Rent" LEAF
    // category (a child of "Group") instead of the "Rent" GROUP.
    file_put_contents(
        $dir.'/Test Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n"
        ."2026-01,Group,Rent,10.00,0.00,0.00\n"
        ."2026-01,Rent,Something,20.00,0.00,0.00\n",
    );

    $parser = app(Ynab4Parser::class);
    $batch = $parser->parse($dir, $this->user, 1);

    $categories = $batch->categories;

    // Both group-parent rows must exist as distinct top-level categories
    // (no sourceGroupName, no parentSourceExternalId).
    $groupParents = $categories->filter(static fn ($c) => $c->parentSourceExternalId === null);
    expect($groupParents->pluck('name')->all())->toContain('Group', 'Rent');
    expect($groupParents)->toHaveCount(2);

    $groupBucketParent = $categories->first(static fn ($c) => $c->name === 'Group' && $c->parentSourceExternalId === null);
    $rentGroupParent = $categories->first(static fn ($c) => $c->name === 'Rent' && $c->parentSourceExternalId === null);
    $rentLeaf = $categories->first(static fn ($c) => $c->name === 'Rent' && $c->parentSourceExternalId !== null);
    $somethingLeaf = $categories->first(static fn ($c) => $c->name === 'Something');

    expect($groupBucketParent)->not->toBeNull();
    expect($rentGroupParent)->not->toBeNull();
    expect($rentLeaf)->not->toBeNull();
    expect($somethingLeaf)->not->toBeNull();

    // The two group-parent keys must be genuinely distinct (the bug made
    // them collide onto the same key).
    expect($groupBucketParent->sourceExternalId)->not->toBe($rentGroupParent->sourceExternalId);

    // The "Rent" LEAF (child of "Group") must resolve to the "Group" bucket.
    expect($rentLeaf->parentSourceExternalId)->toBe($groupBucketParent->sourceExternalId);

    // "Something" must resolve to the "Rent" GROUP parent, never to the
    // "Rent" LEAF category (the silent misattribution CR-01 described).
    expect($somethingLeaf->parentSourceExternalId)->toBe($rentGroupParent->sourceExternalId);
    expect($somethingLeaf->parentSourceExternalId)->not->toBe($rentLeaf->sourceExternalId);

    @unlink($dir.'/Test Register.csv');
    @unlink($dir.'/Test Budget.csv');
    @rmdir($dir);
});
