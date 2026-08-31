<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Public\Enums\CardStatementCreditReason;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

const OSC_ICS_IBAN = 'NL08ABNA0526650664';

/**
 * @return array{user: User, card: Account, bank: Account, icsRun: ImportRun, bankRun: ImportRun}
 */
function oscFixture(DatabaseManager $db, string $suffix): array
{
    $user = User::query()->create([
        'username' => 'osc-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $card = oscAccount($user, 'osc-ics-'.$suffix, AccountKind::IcsCard, 'OSC-ICS-CARD-'.$suffix);
    $bank = oscAccount($user, 'osc-bank-'.$suffix, AccountKind::Bank, 'NL57ASNB000001'.$suffix.'0');

    return [
        'user' => $user,
        'card' => $card,
        'bank' => $bank,
        'icsRun' => oscRun($user, 'osci'.$suffix, 'ics-pdf'),
        'bankRun' => oscRun($user, 'oscb'.$suffix, 'asn-csv'),
    ];
}

function oscAccount(User $user, string $slug, AccountKind $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'osc '.$slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function oscRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/osc.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function oscTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, TransactionType $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
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

function oscStatement(DatabaseManager $db, User $user, Account $card, string $periodStart, string $periodEnd, int $totalMinor): int
{
    return (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'import_run_id' => null,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'total_amount_minor' => $totalMinor,
        'open_balance_minor' => abs($totalMinor),
        'currency' => 'EUR',
        'state' => CardStatementState::Open->value,
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $this->resolver = $resolver;
});

// A surplus of EUR 16.00 is inside March's own tolerance (2% of EUR 847.32)
// and outside April's (2% of EUR 500.00, floored at EUR 5.00), so April
// settles only if the surplus actually reaches it.
it('spends an earlier overpayment on the next statement that lands', function (): void {
    $f = oscFixture($this->db, '1');

    oscTx($this->db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-03-10', -84732, 'osc-march-charge');
    $marchId = oscStatement($this->db, $f['user'], $f['card'], '2026-03-01 00:00:00', '2026-03-31 23:59:59', -84732);
    oscTx($this->db, $f['user'], $f['bank'], $f['bankRun'], TransactionType::TransferOut, '2026-03-29', -86332, 'osc-march-settle', ['counterparty_iban' => OSC_ICS_IBAN]);

    $this->resolver->resolveForUser($f['user']);

    expect((string) $this->db->connection()->table('card_statements')->where('id', $marchId)->value('state'))
        ->toBe(CardStatementState::Overpaid->value);

    $credit = $this->db->connection()->table('card_statement_credits')
        ->where('user_id', $f['user']->id)
        ->where('reason', CardStatementCreditReason::Overpayment->value)
        ->first();
    expect($credit)->not->toBeNull()
        ->and((int) $credit->amount_minor)->toBe(1600);

    // April lands on a later import, which is exactly why the credit was
    // written with nowhere to point.
    oscTx($this->db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-04-10', -50000, 'osc-april-charge');
    $aprilId = oscStatement($this->db, $f['user'], $f['card'], '2026-04-01 00:00:00', '2026-04-30 23:59:59', -50000);
    oscTx($this->db, $f['user'], $f['bank'], $f['bankRun'], TransactionType::TransferOut, '2026-04-29', -48400, 'osc-april-settle', ['counterparty_iban' => OSC_ICS_IBAN]);

    $this->resolver->resolveForUser($f['user']);

    expect((string) $this->db->connection()->table('card_statements')->where('id', $aprilId)->value('state'))
        ->toBe(CardStatementState::Settled->value);
    expect((int) $this->db->connection()->table('card_statement_credits')->where('id', $credit->id)->value('to_statement_id'))
        ->toBe($aprilId);
});

it('leaves a surplus unattached while no later statement has landed', function (): void {
    $f = oscFixture($this->db, '2');

    oscTx($this->db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-03-10', -84732, 'osc-lone-charge');
    oscStatement($this->db, $f['user'], $f['card'], '2026-03-01 00:00:00', '2026-03-31 23:59:59', -84732);
    oscTx($this->db, $f['user'], $f['bank'], $f['bankRun'], TransactionType::TransferOut, '2026-03-29', -86332, 'osc-lone-settle', ['counterparty_iban' => OSC_ICS_IBAN]);

    $this->resolver->resolveForUser($f['user']);
    $this->resolver->resolveForUser($f['user']);

    $credits = $this->db->connection()->table('card_statement_credits')->where('user_id', $f['user']->id)->get();
    expect($credits)->toHaveCount(1)
        ->and($credits->first()->to_statement_id)->toBeNull();
});
