<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Chains\Public\Exceptions\ChainLinkNotDismissableException;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function dchUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function dchAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'dch '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function dchTx(User $user, Account $account, ImportRun $run, int $rowIndex): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'dch '.$rowIndex,
        'counterparty_normalized' => 'dch-'.$rowIndex,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('dchfp'.$rowIndex, 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function dchHint(DatabaseManager $db, User $user, int $fromTxId, string $kind, array $evidence): int
{
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromTxId,
        'to_transaction_id' => null,
        'kind' => $kind,
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode($evidence),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $db->connection()->table('chain_links')->max('id');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = dchUser('dch-user');
    $this->bank = dchAccount($this->user, 'dch-bank', 'bank', 'NL16ASNB0000000001');
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dch.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var DismissChainLinkHint $dismiss */
    $dismiss = $this->app->make(DismissChainLinkHint::class);
    $this->dismiss = $dismiss;
});

it('hard-deletes a canonical ics_bulk_settle hint with tolerance_used=exceeded', function (): void {
    $tx = dchTx($this->user, $this->bank, $this->run, 1);
    $hintId = dchHint($this->db, $this->user, (int) $tx->id, 'ics_bulk_settle', [
        'tolerance_used' => 'exceeded',
        'unaccounted_delta_minor' => 7413,
    ]);

    ($this->dismiss)($hintId, $this->user);

    expect(ChainLink::query()->where('id', $hintId)->exists())->toBeFalse();
});

it('hard-deletes a funded_by_card_hint row', function (): void {
    $tx = dchTx($this->user, $this->bank, $this->run, 2);
    $hintId = dchHint($this->db, $this->user, (int) $tx->id, 'funded_by_card_hint', [
        'card_last_four' => '1234',
    ]);

    ($this->dismiss)($hintId, $this->user);

    expect(ChainLink::query()->where('id', $hintId)->exists())->toBeFalse();
});

it('refuses to dismiss a row with a concrete to_transaction_id (use confirm/reject instead)', function (): void {
    $from = dchTx($this->user, $this->bank, $this->run, 3);
    $to = dchTx($this->user, $this->bank, $this->run, 4);

    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $from->id,
        'to_transaction_id' => $to->id,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'sig-keep']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $linkId = (int) $this->db->connection()->table('chain_links')->max('id');

    expect(fn () => ($this->dismiss)($linkId, $this->user))
        ->toThrow(ChainLinkNotDismissableException::class);

    expect(ChainLink::query()->where('id', $linkId)->exists())->toBeTrue();
});

it('raises NotFoundHttpException on cross-user invocation (404)', function (): void {
    $other = dchUser('dch-other');
    $otherAccount = dchAccount($other, 'dch-other-acc', 'bank', 'NL99OTHER');
    $otherRun = ImportRun::query()->create([
        'user_id' => $other->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dch-other.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $tx = dchTx($other, $otherAccount, $otherRun, 1);
    $hintId = dchHint($this->db, $other, (int) $tx->id, 'ics_bulk_settle', ['tolerance_used' => 'exceeded']);

    expect(fn () => ($this->dismiss)($hintId, $this->user))
        ->toThrow(NotFoundHttpException::class);

    expect(ChainLink::query()->where('id', $hintId)->exists())->toBeTrue();
});

it('raises NotFoundHttpException when the chain_link id does not exist', function (): void {
    expect(fn () => ($this->dismiss)(999_999, $this->user))
        ->toThrow(NotFoundHttpException::class);
});
