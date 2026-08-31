<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Pots\Internal\Exceptions\AccountCannotHoldPotsException;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Exceptions\GoalAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Exceptions\PotAlreadyLinkedException;
use Modules\Pots\Public\Exceptions\PotNotFoundException;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// PotWriter is the only thing that moves money between the account balance and a
// pot, so every rejection here stands in for an allocation it cannot cover.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/pw.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->writer = app(PotWriter::class);
});

// Gives the account a real balance: unallocated is derived as real minus
// allocated, so without this every funding call has nothing to draw on.
function pwCredit(int $userId, int $accountId, int $runId, int $amountMinor): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "PW{$i}",
        'counterparty_normalized' => "pw{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 5000,
        'fingerprint' => str_pad('pw'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function pwPot(int $userId, int $accountId, string $name = 'Pot', string $status = 'active', ?int $goalId = null): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => $goalId,
        'category_id' => null,
        'name' => $name,
        'currency' => 'EUR',
        'status' => $status,
    ]);

    return $pot;
}

function pwGoal(int $userId, int $accountId, string $name = 'Holiday'): Goal
{
    return Goal::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'name' => $name,
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);
}

it('parses the amount formats a Dutch keyboard produces', function (string $input, ?int $expected): void {
    expect(app(PotWriter::class)->parseAmount($input))->toBe($expected);
})->with([
    'grouped Dutch' => ['1.234,56', 123456],
    'plain decimal' => ['1234.56', 123456],
    'comma decimal' => ['50,00', 5000],
    'whole number' => ['50', 5000],
    'single decimal' => ['9,5', 950],
    'spaces stripped' => [' 1 234,56 ', 123456],
    'non-breaking space' => ["1\u{00A0}234,56", 123456],
    'blank' => ['', null],
    'letters' => ['abc', null],
    'negative' => ['-5', null],
    'zero' => ['0', null],
    'zero with cents' => ['0,00', null],
    'thirteen digits' => ['1234567890123', null],
    // A lone separator reads as a decimal point, so a Dutch thousands grouping
    // has three decimals and is refused — the alternative reading would fund a
    // pot with 1234 instead of 1.23 and say nothing.
    'lone dot grouping' => ['1.234', null],
    'lone comma grouping' => ['1,234', null],
]);

it('refuses to create a pot without a name', function (): void {
    $this->writer->save($this->user, '   ', null, $this->account->id, null, null);
})->throws(InvalidArgumentException::class, 'Enter a name for this pot.');

it('refuses an account belonging to someone else', function (): void {
    $other = User::create(['username' => 'other', 'password' => 'x', 'period_start_day' => 1]);
    $theirAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Theirs',
        'slug' => 'theirs',
        'kind' => 'bank',
        'iban' => 'NL01ASNB9999999999',
        'default_currency' => 'EUR',
    ]);

    $this->writer->save($this->user, 'Pot', null, $theirAccount->id, null, null);
})->throws(InvalidArgumentException::class, 'Account not owned by the authenticated user.');

it('refuses a category link, which the product withdrew in favour of goals', function (): void {
    $this->writer->save($this->user, 'Pot', null, $this->account->id, null, 7);
})->throws(InvalidArgumentException::class, 'Pots can no longer be linked to a category');

it('refuses a goal owned by someone else', function (): void {
    $other = User::create(['username' => 'other2', 'password' => 'x', 'period_start_day' => 1]);
    $theirGoal = pwGoal($other->id, $this->account->id, 'Theirs');

    $this->writer->save($this->user, 'Pot', null, $this->account->id, $theirGoal->id, null);
})->throws(InvalidArgumentException::class, 'Goal not found or not owned by user.');

it('creates the pot with the currency of its account', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, null, null);

    expect($pot->currency)->toBe('EUR')
        ->and($pot->status)->toBe('active')
        ->and($pot->user_id)->toBe($this->user->id);
});

it('funds the pot in the same breath when an initial amount is given', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);

    $pot = $this->writer->save($this->user, 'Buffer', '100,00', $this->account->id, null, null);

    $movement = DB::table('pot_movements')->where('pot_id', $pot->id)->first();
    expect($movement->amount_minor)->toBe(10000)
        ->and($movement->kind)->toBe('fund');
});

it('rejects an unparseable initial amount before writing anything', function (): void {
    try {
        $this->writer->save($this->user, 'Buffer', 'not-a-number', $this->account->id, null, null);
        $this->fail('expected an InvalidArgumentException');
    } catch (InvalidArgumentException) {
        // The parse happens before the insert precisely so this cannot leave a
        // pot behind for the user to trip over on the next attempt.
        expect(Pot::query()->withoutGlobalScope(UserScope::class)->count())->toBe(0);
    }
});

it('leaves no orphan pot when the initial funding exceeds what the account has', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 5000);

    try {
        $this->writer->save($this->user, 'Buffer', '100,00', $this->account->id, null, null);
        $this->fail('expected an InsufficientUnallocatedException');
    } catch (InsufficientUnallocatedException) {
        // Creation and funding share a transaction, so the failed check has to
        // take the pot row with it.
        expect(Pot::query()->withoutGlobalScope(UserScope::class)->count())->toBe(0);
    }
});

it('refuses to update a pot the user does not own', function (): void {
    $other = User::create(['username' => 'other3', 'password' => 'x', 'period_start_day' => 1]);
    $theirPot = pwPot($other->id, $this->account->id);

    $this->writer->update($this->user, $theirPot->id, 'Renamed', null, null);
})->throws(PotNotFoundException::class);

it('refuses to blank the name of an existing pot', function (): void {
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->update($this->user, $pot->id, '  ', null, null);
})->throws(InvalidArgumentException::class, 'Enter a name for this pot.');

it('renames a pot and re-points its goal', function (): void {
    $pot = pwPot($this->user->id, $this->account->id, 'Old');
    $goal = pwGoal($this->user->id, $this->account->id);

    $updated = $this->writer->update($this->user, $pot->id, 'New', $goal->id, null);

    expect($updated->name)->toBe('New')
        ->and($updated->goal_id)->toBe($goal->id);
});

it('refuses to link a goal that another active pot already holds', function (): void {
    $goal = pwGoal($this->user->id, $this->account->id);
    pwPot($this->user->id, $this->account->id, 'First', 'active', $goal->id);
    $second = pwPot($this->user->id, $this->account->id, 'Second');

    $this->writer->update($this->user, $second->id, 'Second', $goal->id, null);
})->throws(GoalAlreadyLinkedException::class);

// Same rule, the other write path. update() is reached from the pots page and
// linkGoal() from the goals page, and only the first was covered.
it('refuses that goal through linkGoal as well as through update', function (): void {
    $goal = pwGoal($this->user->id, $this->account->id);
    pwPot($this->user->id, $this->account->id, 'First', 'active', $goal->id);
    $second = pwPot($this->user->id, $this->account->id, 'Second');

    $this->writer->linkGoal($this->user, $second->id, $goal->id);
})->throws(GoalAlreadyLinkedException::class);

// The reverse direction, which shipped unguarded: one pot, two goals. A goal
// write handed itself a pot another goal already held, the pot moved, and the
// goal that had been funded by it read 0% with no pot and no sign why.
it('refuses to hand a second goal a pot the first goal is already funded by', function (): void {
    $first = pwGoal($this->user->id, $this->account->id, 'Japan trip');
    $second = pwGoal($this->user->id, $this->account->id, 'Winterbanden');
    $pot = pwPot($this->user->id, $this->account->id, 'Reispot', 'active', $first->id);

    try {
        $this->writer->linkGoal($this->user, $pot->id, $second->id);
        $this->fail('expected a PotAlreadyLinkedException');
    } catch (PotAlreadyLinkedException) {
        expect($pot->fresh()->goal_id)->toBe($first->id);
    }
});

it('still lets a free pot take a goal', function (): void {
    $goal = pwGoal($this->user->id, $this->account->id);
    $pot = pwPot($this->user->id, $this->account->id, 'Vrij');

    $this->writer->linkGoal($this->user, $pot->id, $goal->id);

    expect($pot->fresh()->goal_id)->toBe($goal->id);
});

it('still lets a linked pot be unlinked', function (): void {
    $goal = pwGoal($this->user->id, $this->account->id);
    $pot = pwPot($this->user->id, $this->account->id, 'Gekoppeld', 'active', $goal->id);

    $this->writer->linkGoal($this->user, $pot->id, null);

    expect($pot->fresh()->goal_id)->toBeNull();
});

it('refuses a pot on an account that holds no allocatable balance', function (): void {
    $card = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Card',
        'slug' => 'card',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'NL11ICSB0000000002',
        'default_currency' => 'EUR',
    ]);

    $this->writer->save($this->user, 'Kaartpot', null, $card->id, null, null);
})->throws(AccountCannotHoldPotsException::class);

it('refuses to fund an amount the account cannot cover', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 1000);
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->fund($this->user, $pot->id, '100,00');
})->throws(InsufficientUnallocatedException::class);

it('refuses to fund a pot that is not the user\'s', function (): void {
    $other = User::create(['username' => 'other4', 'password' => 'x', 'period_start_day' => 1]);
    $theirPot = pwPot($other->id, $this->account->id);

    $this->writer->fund($this->user, $theirPot->id, '1,00');
})->throws(PotNotFoundException::class);

it('records the memo against the funding movement', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->fund($this->user, $pot->id, '25,00', 'birthday money');

    $movement = DB::table('pot_movements')->where('pot_id', $pot->id)->first();
    expect($movement->amount_minor)->toBe(2500)
        ->and($movement->memo)->toBe('birthday money');
});

it('writes a withdrawal as a negative movement', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = pwPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');

    $this->writer->withdraw($this->user, $pot->id, '40,00');

    $movement = DB::table('pot_movements')->where('kind', 'withdraw')->first();
    expect($movement->amount_minor)->toBe(-4000);
});

it('refuses to withdraw more than the pot holds', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = pwPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '10,00');

    $this->writer->withdraw($this->user, $pot->id, '40,00');
})->throws(InsufficientUnallocatedException::class, 'Amount exceeds balance in this pot.');

it('rejects a zero or unparseable amount on both fund and withdraw', function (string $method): void {
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->{$method}($this->user, $pot->id, '0,00');
})->with(['fund', 'withdraw'])->throws(InvalidArgumentException::class);

// The ownership check matters more on the way out than on the way in: funding
// someone else's pot only misplaces the caller's own money, whereas
// withdrawing from it would move money that is not theirs.
it('refuses to withdraw from a pot that is not the user\'s', function (): void {
    $other = User::create(['username' => 'other5', 'password' => 'x', 'period_start_day' => 1]);
    $theirPot = pwPot($other->id, $this->account->id);

    $this->writer->withdraw($this->user, $theirPot->id, '1,00');
})->throws(PotNotFoundException::class, 'Pot not found or not owned by user.');

it('moves money between two pots as a mirrored pair of movements', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $from = pwPot($this->user->id, $this->account->id, 'From');
    $to = pwPot($this->user->id, $this->account->id, 'To');
    $this->writer->fund($this->user, $from->id, '100,00');

    $this->writer->transfer($this->user, $from->id, $to->id, '30,00', 'rebalance');

    $out = DB::table('pot_movements')->where('kind', 'transfer_out')->first();
    $in = DB::table('pot_movements')->where('kind', 'transfer_in')->first();

    expect($out->amount_minor)->toBe(-3000)
        ->and($out->counterpart_pot_id)->toBe($to->id)
        ->and($in->amount_minor)->toBe(3000)
        ->and($in->counterpart_pot_id)->toBe($from->id);
});

it('refuses a transfer into the same pot', function (): void {
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->transfer($this->user, $pot->id, $pot->id, '1,00');
})->throws(InvalidArgumentException::class, 'Source and target pot must be different.');

// Both the amount and the same-pot rule reject this call, and the amount has to
// win: a message naming the pots would be a red herring when the real problem is
// the field the user typed in.
it('rejects an unparseable transfer amount before it compares the two pots', function (): void {
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->transfer($this->user, $pot->id, $pot->id, 'nonsense');
})->throws(InvalidArgumentException::class, 'Invalid or non-positive amount.');

it('refuses a transfer across accounts, which would move money invisibly', function (): void {
    $second = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Other',
        'slug' => 'other',
        'kind' => 'bank',
        'iban' => 'NL02ASNB1111111111',
        'default_currency' => 'EUR',
    ]);
    $from = pwPot($this->user->id, $this->account->id, 'From');
    $to = pwPot($this->user->id, $second->id, 'To');

    $this->writer->transfer($this->user, $from->id, $to->id, '1,00');
})->throws(InvalidArgumentException::class, 'same account');

it('refuses to transfer more than the source pot holds', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $from = pwPot($this->user->id, $this->account->id, 'From');
    $to = pwPot($this->user->id, $this->account->id, 'To');
    $this->writer->fund($this->user, $from->id, '10,00');

    $this->writer->transfer($this->user, $from->id, $to->id, '40,00');
})->throws(InsufficientUnallocatedException::class, 'Amount exceeds balance in the source pot.');

it('reports which side of a transfer is missing', function (): void {
    $from = pwPot($this->user->id, $this->account->id, 'From');

    $this->writer->transfer($this->user, $from->id, 999999, '1,00');
})->throws(PotNotFoundException::class, 'Target pot not found or not owned by user.');

it('releases the balance back to the account when a pot is archived', function (): void {
    pwCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = pwPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');

    $this->writer->archive($this->user, $pot->id);

    // The release is named by its own kind, not by a sentence in the memo:
    // `memo` syncs, so English written there would have reached a peer frozen.
    $release = DB::table('pot_movements')
        ->where('kind', PotMovementKind::ReleasedOnArchive->value)
        ->first();
    expect($release)->not->toBeNull();
    expect($release->memo)->toBeNull();
    expect((int) $release->amount_minor)->toBe(-10000)
        ->and($pot->fresh()->status)->toBe('archived');
});

it('archives an empty pot without inventing a movement', function (): void {
    $pot = pwPot($this->user->id, $this->account->id);

    $this->writer->archive($this->user, $pot->id);

    expect(DB::table('pot_movements')->count())->toBe(0)
        ->and($pot->fresh()->status)->toBe('archived');
});

it('ignores an archive or restore for a pot that is not there', function (string $method): void {
    $this->writer->{$method}($this->user, 999999);
})->with(['archive', 'restore'])->throwsNoExceptions();

it('restores an archived pot to active', function (): void {
    $pot = pwPot($this->user->id, $this->account->id, 'Old', 'archived');

    $this->writer->restore($this->user, $pot->id);

    expect($pot->fresh()->status)->toBe('active');
});

it('drops the goal link when restoring would give one goal two active pots', function (): void {
    $goal = pwGoal($this->user->id, $this->account->id);
    $archived = pwPot($this->user->id, $this->account->id, 'Archived', 'archived', $goal->id);
    pwPot($this->user->id, $this->account->id, 'Claimed it since', 'active', $goal->id);

    $this->writer->restore($this->user, $archived->id);

    // Restoring is not allowed to produce the second active pot on one goal,
    // so the link is dropped rather than the restore refused.
    expect($archived->fresh()->status)->toBe('active')
        ->and($archived->fresh()->goal_id)->toBeNull();
});
