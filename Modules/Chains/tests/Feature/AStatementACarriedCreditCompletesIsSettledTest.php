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

const SCC_ICS_IBAN = 'NL08ABNA0526650664';

/**
 * @return array{user: User, card: Account, bank: Account, icsRun: ImportRun, bankRun: ImportRun}
 */
function sccFixture(string $suffix): array
{
    $user = User::query()->create([
        'username' => 'scc-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    return [
        'user' => $user,
        'card' => sccAccount($user, 'scc-ics-'.$suffix, AccountKind::IcsCard, 'SCC-ICS-CARD-'.$suffix),
        'bank' => sccAccount($user, 'scc-bank-'.$suffix, AccountKind::Bank, 'NL57ASNB000002'.$suffix.'0'),
        'icsRun' => sccRun($user, 'scci'.$suffix, 'ics-pdf'),
        'bankRun' => sccRun($user, 'sccb'.$suffix, 'asn-csv'),
    ];
}

function sccAccount(User $user, string $slug, AccountKind $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'scc '.$slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function sccRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/scc.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sccTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, TransactionType $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
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
        'source_row_index' => crc32($normalized.$postedAt.$settledMinor) % 100000,
        'fingerprint' => substr(hash('sha256', $normalized.$postedAt.$settledMinor.$user->id), 0, 64),
        'fingerprint_version' => 3,
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ], $overrides));
}

function sccStatement(DatabaseManager $db, User $user, Account $card, string $periodStart, string $periodEnd, int $totalMinor): int
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

// The delta the tolerance test is taken over counts the carried credit, and
// the reader's payment plus that credit covers the charges exactly. The state
// machine was told about the payment alone, so a statement nobody owed
// anything on any more read back as still half paid.
it('closes a statement whose remainder a refund carried forward already paid', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $f = sccFixture('1');

    sccTx($db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-03-10', -83132, 'scc-march-bulk');
    sccTx($db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-03-11', -1600, 'scc-returned-item');
    $marchId = sccStatement($db, $f['user'], $f['card'], '2026-03-01 00:00:00', '2026-03-31 23:59:59', -84732);
    sccTx($db, $f['user'], $f['bank'], $f['bankRun'], TransactionType::TransferOut, '2026-03-29', -84732, 'scc-march-settle', ['counterparty_iban' => SCC_ICS_IBAN]);

    // The refund lands after March closed, so it carries forward to April
    // rather than reducing the statement it belongs to.
    sccTx($db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Refund, '2026-03-20', 1600, 'scc-returned-item');

    sccTx($db, $f['user'], $f['card'], $f['icsRun'], TransactionType::Expense, '2026-04-10', -50000, 'scc-april-charge');
    $aprilId = sccStatement($db, $f['user'], $f['card'], '2026-04-01 00:00:00', '2026-04-30 23:59:59', -50000);
    sccTx($db, $f['user'], $f['bank'], $f['bankRun'], TransactionType::TransferOut, '2026-04-29', -48400, 'scc-april-settle', ['counterparty_iban' => SCC_ICS_IBAN]);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($f['user']);
    $resolver->resolveForUser($f['user']);

    expect((string) $db->connection()->table('card_statements')->where('id', $marchId)->value('state'))
        ->toBe(CardStatementState::Settled->value);

    $credit = $db->connection()->table('card_statement_credits')
        ->where('user_id', $f['user']->id)
        ->where('reason', CardStatementCreditReason::RefundAfterClose->value)
        ->first();
    expect($credit)->not->toBeNull()
        ->and((int) $credit->to_statement_id)->toBe($aprilId);

    expect((string) $db->connection()->table('card_statements')->where('id', $aprilId)->value('state'))
        ->toBe(CardStatementState::Settled->value);
    expect((int) $db->connection()->table('card_statements')->where('id', $aprilId)->value('open_balance_minor'))
        ->toBe(0);
});
