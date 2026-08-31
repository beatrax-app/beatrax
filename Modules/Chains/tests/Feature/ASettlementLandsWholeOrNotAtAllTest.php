<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

const AWN_ICS_IBAN = 'NL08ABNA0526650664';

function awnUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    return $user;
}

function awnAccount(User $user, string $slug, AccountKind $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'awn '.$slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function awnRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/awn.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function awnTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, TransactionType $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
{
    return (int) $db->connection()->table('transactions')->insertGetId(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Name '.$normalized,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => $run->source_format,
        'import_run_id' => $run->id,
        'source_row_index' => crc32($normalized.$postedAt) % 100000,
        'fingerprint' => substr(hash('sha256', $normalized.$postedAt.$user->id), 0, 64),
        'fingerprint_version' => 3,
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ], $overrides));
}

function awnStatement(DatabaseManager $db, User $user, Account $card, ImportRun $run, string $start, string $end, int $totalMinor, CardStatementState $state): int
{
    return (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'import_run_id' => $run->id,
        'period_start' => $start,
        'period_end' => $end,
        'total_amount_minor' => $totalMinor,
        'open_balance_minor' => abs($totalMinor),
        'currency' => 'EUR',
        'state' => $state->value,
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ]);
}

/**
 * A March statement covering three card expenses and the bank transfer that
 * settles it exactly, plus a closed February statement holding a refund the
 * second pass carries forward into March.
 */
function awnFixture(DatabaseManager $db): User
{
    $user = awnUser('awn-owner');
    $card = awnAccount($user, 'awn-ics', AccountKind::IcsCard, 'AWN-ICS-CARD');
    $bank = awnAccount($user, 'awn-asn', AccountKind::Bank, 'NL57ASNB0123456789');

    $icsRun = awnRun($user, 'awni', 'ics-pdf');
    $asnRun = awnRun($user, 'awna', 'asn-csv');

    $total = 0;
    for ($i = 1; $i <= 3; $i++) {
        $amount = -(1000 + $i);
        $total += $amount;
        awnTx($db, $user, $card, $icsRun, TransactionType::Expense, '2026-03-'.str_pad((string) ($i + 4), 2, '0', STR_PAD_LEFT), $amount, 'awn-merchant-'.$i);
    }
    awnStatement($db, $user, $card, $icsRun, '2026-03-01 00:00:00', '2026-03-31 23:59:59', $total, CardStatementState::Open);
    awnTx($db, $user, $bank, $asnRun, TransactionType::TransferOut, '2026-03-29', $total, 'awn-ics-settle', ['counterparty_iban' => AWN_ICS_IBAN]);

    // Closed February, with a purchase and its later refund: the refund pass
    // writes one chain_link and one carry-forward credit per matched refund.
    awnStatement($db, $user, $card, $icsRun, '2026-02-01 00:00:00', '2026-02-28 23:59:59', -4000, CardStatementState::Settled);
    awnTx($db, $user, $card, $icsRun, TransactionType::Expense, '2026-02-10', -4000, 'awn-returned-merchant');
    awnTx($db, $user, $card, $icsRun, TransactionType::Refund, '2026-02-20', 4000, 'awn-returned-merchant');

    return $user;
}

function awnLinkCount(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('chain_links')
        ->where('user_id', $user->id)
        ->where('kind', ChainLinkKind::IcsBulkSettle->value)
        ->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-04-05 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes no chain_link when the settlement it belongs to cannot be applied', function (): void {
    $user = awnFixture($this->db);

    // The failure the resolver has no recovery from: candidateTransferIds()
    // drops a transfer once it carries a confirmed link, so links written
    // without the statement move leave that statement open forever.
    $this->db->connection()->statement(
        "CREATE TRIGGER awn_block_statement_update BEFORE UPDATE ON card_statements FOR EACH ROW
         BEGIN SELECT RAISE(ABORT, 'awn blocked the settlement'); END"
    );

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);

    expect(static fn () => $resolver->resolveForUser($user))->toThrow(QueryException::class);

    expect(awnLinkCount($this->db, $user))->toBe(0);
    expect((string) $this->db->connection()->table('card_statements')
        ->where('user_id', $user->id)
        ->where('period_start', '2026-03-01 00:00:00')
        ->value('state'))->toBe(CardStatementState::Open->value);
});

it('writes no refund chain_link when the credit it carries forward cannot be written', function (): void {
    $user = awnFixture($this->db);

    $this->db->connection()->statement(
        "CREATE TRIGGER awn_block_credit_insert BEFORE INSERT ON card_statement_credits FOR EACH ROW
         BEGIN SELECT RAISE(ABORT, 'awn blocked the credit'); END"
    );

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);

    expect(static fn () => $resolver->resolveForUser($user))->toThrow(QueryException::class);

    // The refund pass runs after the main pass, so the three settled links
    // survive; what must not survive is the refund's own link without the
    // credit that link stands for.
    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $user->id)
        ->where('kind', ChainLinkKind::IcsBulkSettle->value)
        ->whereIn('from_transaction_id', $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Refund->value)
            ->pluck('id')
            ->all())
        ->count())->toBe(0);
});
