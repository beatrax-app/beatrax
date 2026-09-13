<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// The envelope cutover is the one archiving nobody asks for: every device runs
// it for itself at its own first launch after the update, over the same pots.
// Two devices apart each wrote their own settlement, both landed as creates,
// and the archived pot read at minus what it had held.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-12 09:00:00');

    $this->user = User::create([
        'username' => 'settle-once-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'settle-once-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/settle-once.xml',
        'sha256' => hash('sha256', 'settle-once-'.bin2hex(random_bytes(4))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'settle-once-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->writer = app(PotWriter::class);
    $this->balances = app(PotBalanceQuery::class);
});

// Real money in the account, so a fund has something to be carved out of.
function settleOnceCredit(int $userId, int $accountId, int $runId, int $amountMinor): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => '2026-08-01',
        'booked_at' => '2026-08-01 12:00:00',
        'value_date' => '2026-08-01',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => sprintf('SO%d', $i),
        'counterparty_normalized' => sprintf('so%d', $i),
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 9100,
        'fingerprint' => str_pad('so'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function settleOnceCategoryPot(int $userId, int $accountId, ?int $categoryId): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => $categoryId,
        'name' => 'Boodschappen',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return $pot;
}

/** @return list<object> */
function settleOnceSettlements(int $potId): array
{
    return DB::table('pot_movements')
        ->where('pot_id', $potId)
        ->where('kind', PotMovementKind::ReleasedOnArchive->value)
        ->orderBy('id')
        ->get()
        ->all();
}

// What device B held while the two were apart: its own copy of the pot, still
// active, with the funding and none of A's cutover. Restoring that state and
// running the real walk again is the second device, not a second call.
function settleOnceRewindToBeforeTheCutover(int $userId, int $potId, int $settlementId): void
{
    DB::table('pot_movements')->where('id', $settlementId)->delete();
    DB::table('pots')->where('id', $potId)->update(['status' => 'active']);
    DB::table('users')->where('id', $userId)->update(['envelope_activated_at' => null]);
}

it('settles a category-linked pot once when both devices run the cutover apart', function (): void {
    settleOnceCredit($this->user->id, $this->account->id, $this->run->id, 100_000);
    $pot = settleOnceCategoryPot($this->user->id, $this->account->id, $this->category->id);
    DB::table('pot_movements')->insert([
        'id' => 7_001,
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 10_000,
        'currency' => 'EUR',
        'kind' => PotMovementKind::Fund->value,
        'memo' => null,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    app(EnvelopeActivationService::class)->activate();
    $deviceA = (array) settleOnceSettlements($pot->id)[0];

    settleOnceRewindToBeforeTheCutover($this->user->id, $pot->id, (int) $deviceA['id']);

    app(EnvelopeActivationService::class)->activate();
    $deviceB = (array) settleOnceSettlements($pot->id)[0];

    // The merge. An arriving `create_row` is a plain insert — pot_movements
    // carries no payload gate and no UNIQUE — and the ids are minted, so both
    // settlements are in the ledger and neither is going away.
    DB::table('pot_movements')->insert([$deviceA]);

    expect(settleOnceSettlements($pot->id))->toHaveCount(2)
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0);
});

// The other half of the same reading. Restoring writes no movements, so a pot
// that came back at minus what it held claimed a negative allocation, and the
// account's unallocated line read as money it does not hold.
it('brings a twice-settled pot back empty, and leaves unallocated where it was', function (): void {
    settleOnceCredit($this->user->id, $this->account->id, $this->run->id, 100_000);
    $pot = settleOnceCategoryPot($this->user->id, $this->account->id, $this->category->id);
    DB::table('pot_movements')->insert([
        'id' => 7_002,
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 10_000,
        'currency' => 'EUR',
        'kind' => PotMovementKind::Fund->value,
        'memo' => null,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    app(EnvelopeActivationService::class)->activate();
    $deviceA = (array) settleOnceSettlements($pot->id)[0];

    settleOnceRewindToBeforeTheCutover($this->user->id, $pot->id, (int) $deviceA['id']);
    app(EnvelopeActivationService::class)->activate();
    DB::table('pot_movements')->insert([$deviceA]);

    app(PotWriter::class)->restore($this->user, $pot->id);

    $row = $this->balances->reconciliationForAccount($this->account->id, $this->user, 'EUR');

    expect($this->balances->balanceForPot($pot->id, $this->user))->toBe(0)
        ->and($row->realBalanceMinor)->toBe(100_000)
        ->and($row->allocatedMinor)->toBe(0)
        ->and($row->unallocatedMinor)->toBe(100_000);
});

it('still settles a pot archived a second time, because the settlements are counted', function (): void {
    settleOnceCredit($this->user->id, $this->account->id, $this->run->id, 100_000);
    $pot = settleOnceCategoryPot($this->user->id, $this->account->id, null);

    $this->writer->fund($this->user, $pot->id, '100,00');
    $this->writer->archive($this->user, $pot->id);

    // A second archiving is a later day, not a later row: the clock is what
    // orders a settlement, because the id is a random draw.
    CarbonImmutable::setTestNow('2026-08-19 09:00:00');

    $this->writer->restore($this->user, $pot->id);
    $this->writer->fund($this->user, $pot->id, '100,00');
    $this->writer->archive($this->user, $pot->id);

    // Two archivings are two settlements. Keyed on the pot alone the second
    // would name the first's row, and the pot would come to rest holding money
    // nothing is reconciling against it.
    $settlements = settleOnceSettlements($pot->id);
    expect($settlements)->toHaveCount(2)
        ->and($settlements[0]->id)->not->toBe($settlements[1]->id)
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0);
});

it('leaves a fund and a withdrawal minted, because two deposits are two deposits', function (): void {
    settleOnceCredit($this->user->id, $this->account->id, $this->run->id, 100_000);
    $pot = settleOnceCategoryPot($this->user->id, $this->account->id, null);

    $this->writer->fund($this->user, $pot->id, '50,00');
    $this->writer->fund($this->user, $pot->id, '50,00');

    $funds = DB::table('pot_movements')
        ->where('pot_id', $pot->id)
        ->where('kind', PotMovementKind::Fund->value)
        ->pluck('id')
        ->all();

    expect($funds)->toHaveCount(2)
        ->and($funds[0])->not->toBe($funds[1])
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(10_000);
});
