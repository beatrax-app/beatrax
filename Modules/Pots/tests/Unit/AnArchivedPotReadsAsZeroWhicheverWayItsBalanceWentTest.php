<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// Archiving is the write that settles a pot, and a pot settled at anything but
// nought is still claiming or still owing part of an account balance nothing
// will ever reconcile against it again.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'archive-zero-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'archive-zero-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/archive-zero.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->writer = app(PotWriter::class);
    $this->balances = app(PotBalanceQuery::class);
});

// Real money in the account, so unallocated has something to be derived from:
// it is `real - allocated`, and without this every figure below starts at nought.
function archiveZeroCredit(int $userId, int $accountId, int $runId, int $amountMinor): void
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
        'counterparty_name' => "AZ{$i}",
        'counterparty_normalized' => "az{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 7000,
        'fingerprint' => str_pad('az'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function archiveZeroPot(int $userId, int $accountId): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Vakantie',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return $pot;
}

// The row shape OpLogEntryApplier writes for an arriving create_row. The writer
// refuses a withdrawal past the balance, so the only way a pot goes below nought
// is a second device that took the same money while the two were apart.
function archiveZeroPeerWithdrawal(int $userId, int $potId, int $amountMinor): void
{
    static $peerRowId = 4_242_424_242;
    $peerRowId++;

    DB::table('pot_movements')->insert([
        'id' => $peerRowId,
        'user_id' => $userId,
        'pot_id' => $potId,
        'counterpart_pot_id' => null,
        'amount_minor' => -$amountMinor,
        'currency' => 'EUR',
        'kind' => PotMovementKind::Withdraw->value,
        'memo' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

/** @return list<object> */
function archiveZeroReleases(int $potId): array
{
    return DB::table('pot_movements')
        ->where('pot_id', $potId)
        ->where('kind', PotMovementKind::ReleasedOnArchive->value)
        ->orderBy('id')
        ->get()
        ->all();
}

it('brings a pot two devices emptied apart back up to nought when it is archived', function (): void {
    archiveZeroCredit($this->user->id, $this->account->id, $this->run->id, 10000);
    $pot = archiveZeroPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');
    $this->writer->withdraw($this->user, $pot->id, '100,00');
    archiveZeroPeerWithdrawal($this->user->id, $pot->id, 10000);

    expect($this->balances->balanceForPot($pot->id, $this->user))->toBe(-10000);

    $this->writer->archive($this->user, $pot->id);

    $releases = archiveZeroReleases($pot->id);
    expect($releases)->toHaveCount(1)
        ->and((int) $releases[0]->amount_minor)->toBe(10000)
        ->and($releases[0]->currency)->toBe('EUR')
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0)
        ->and($pot->fresh()->status)->toBe('archived');
});

it('leaves unallocated at what the account actually holds once the overdrawn pot is gone', function (): void {
    archiveZeroCredit($this->user->id, $this->account->id, $this->run->id, 10000);
    $pot = archiveZeroPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');
    $this->writer->withdraw($this->user, $pot->id, '100,00');
    archiveZeroPeerWithdrawal($this->user->id, $pot->id, 10000);

    // The pot claims minus a hundred, so unallocated reads two hundred over an
    // account holding one: the overstatement archiving has to take back.
    $before = $this->balances->reconciliationForAccount($this->account->id, $this->user, 'EUR');
    expect($before->unallocatedMinor)->toBe(20000)
        ->and($before->isOverAllocated)->toBeFalse();

    $this->writer->archive($this->user, $pot->id);

    // Unallocated lands here either way, because `allocated` counts active pots
    // only and an archived one drops out of it whatever its movements sum to.
    // The pot reading nought is the half that needs the release.
    $after = $this->balances->reconciliationForAccount($this->account->id, $this->user, 'EUR');
    expect($after->realBalanceMinor)->toBe(10000)
        ->and($after->allocatedMinor)->toBe(0)
        ->and($after->unallocatedMinor)->toBe(10000)
        ->and($after->isOverAllocated)->toBeFalse()
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0);
});

it('still releases a pot that holds money as the negative movement it has always been', function (): void {
    archiveZeroCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $pot = archiveZeroPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');

    $this->writer->archive($this->user, $pot->id);

    $releases = archiveZeroReleases($pot->id);
    expect($releases)->toHaveCount(1)
        ->and((int) $releases[0]->amount_minor)->toBe(-10000)
        ->and($releases[0]->memo)->toBeNull()
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0)
        ->and($pot->fresh()->status)->toBe('archived');
});

it('writes nothing at all for a pot that already sits at nought', function (): void {
    archiveZeroCredit($this->user->id, $this->account->id, $this->run->id, 10000);
    $pot = archiveZeroPot($this->user->id, $this->account->id);
    $this->writer->fund($this->user, $pot->id, '100,00');
    $this->writer->withdraw($this->user, $pot->id, '100,00');

    expect($this->balances->balanceForPot($pot->id, $this->user))->toBe(0);

    $this->writer->archive($this->user, $pot->id);

    expect(archiveZeroReleases($pot->id))->toBe([])
        ->and(DB::table('pot_movements')->where('pot_id', $pot->id)->count())->toBe(2)
        ->and($this->balances->balanceForPot($pot->id, $this->user))->toBe(0)
        ->and($pot->fresh()->status)->toBe('archived');
});
