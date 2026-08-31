<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// `ics_bulk_settle` runs FROM the one bank payment TO each charge it covered,
// so a node the drawer reached ALONG that link is the charge, not a settlement.
// The empty-fan-out box keyed off the link's kind, not the node's side, so it
// printed "No ICS charges in this settlement" under a covered charge.

function coveredChargeUser(): User
{
    return User::query()->create([
        'username' => 'covered-charge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function coveredChargeAccount(User $user, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'covered '.$kind,
        'slug' => 'covered-'.$kind.'-'.bin2hex(random_bytes(3)),
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function coveredChargeTx(User $user, Account $account, ImportRun $run, int $amountMinor, string $type, string $name, string $postedAt, int $rowIndex): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $name,
        'counterparty_normalized' => strtolower($name),
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', 'covered-charge-'.$user->id.'-'.$rowIndex),
        'fingerprint_version' => 3,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function coveredChargeLink(DatabaseManager $db, User $user, int $fromId, int $toId, array $evidence): void
{
    $db->connection()->table('chain_links')->insert([
        'id' => ChainLinkInsertHelper::idFor($user->id, $fromId, $toId, 'ics_bulk_settle'),
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => 'ics_bulk_settle',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode($evidence),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = coveredChargeUser();
    $this->bank = coveredChargeAccount($this->user, 'bank', 'NL57ASNB0123456789');
    $this->ics = coveredChargeAccount($this->user, 'ics_card', 'ICS-COVERED');
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/covered.pdf',
        'sha256' => hash('sha256', 'covered-run-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

// One statement, one charge: the resolver writes exactly one link, which the
// drawer keeps in the flat waterfall rather than nesting a fan-out of one.
it('does not tell the reader a covered charge covers no charges', function (): void {
    $settlement = coveredChargeTx($this->user, $this->bank, $this->run, -42000, 'transfer_out', 'ICS Cards', '2026-05-20', 1);
    $charge = coveredChargeTx($this->user, $this->ics, $this->run, -42000, 'expense', 'Bol.com', '2026-05-10', 2);

    coveredChargeLink($this->db, $this->user, (int) $settlement->id, (int) $charge->id, [
        'signature_hash' => 'covered-sig',
        'tolerance_used' => 'amount_5eur',
    ]);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $charge->id)
        ->assertDontSee('No ICS charges in this settlement');
});

// The refund-after-close pass writes refund -> original purchase under the same
// kind. Neither end is a settlement and neither covers a card charge.
it('does not tell the reader a refund leg covers no charges', function (): void {
    $original = coveredChargeTx($this->user, $this->ics, $this->run, -5000, 'expense', 'Zalando', '2026-05-02', 1);
    $refund = coveredChargeTx($this->user, $this->ics, $this->run, 5000, 'refund', 'Zalando', '2026-05-25', 2);

    coveredChargeLink($this->db, $this->user, (int) $refund->id, (int) $original->id, [
        'signature_hash' => 'refund-sig',
        'tolerance_used' => 'refund_after_close',
    ]);

    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $original->id)
        ->assertDontSee('No ICS charges in this settlement');
});
