<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

const NSA_ICS_IBAN = 'NL08ABNA0526650664';

/**
 * The links this fixture reads are written by the real resolver, not by hand:
 * `from` is the ONE payment and `to` is each charge it covered, and a fixture
 * spelling that pair the other way round is the only thing under which the
 * read site's own join names the right column.
 *
 * @return array{user: User, card: Account, payerOne: Account, payerTwo: Account, marchId: int}
 */
function nsaFixture(DatabaseManager $db, string $suffix): array
{
    $user = User::query()->create([
        'username' => 'nsa-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $card = nsaAccount($user, 'nsa-ics-'.$suffix, AccountKind::IcsCard, 'NSA-ICS-CARD-'.$suffix);
    // Two banks, created in this order: the read site used to answer with
    // whichever came first by id regardless of which one had ever paid.
    $payerOne = nsaAccount($user, 'nsa-payer-one-'.$suffix, AccountKind::Bank, 'NL57ASNB000000'.$suffix.'1');
    $payerTwo = nsaAccount($user, 'nsa-payer-two-'.$suffix, AccountKind::Bank, 'NL57ASNB000000'.$suffix.'2');

    $icsRun = nsaRun($user, 'nsai'.$suffix, 'ics-pdf');
    $payerRun = nsaRun($user, 'nsap'.$suffix, 'asn-csv');

    $total = 0;
    for ($i = 1; $i <= 3; $i++) {
        $amount = -(2000 + $i);
        $total += $amount;
        nsaTx($db, $user, $card, $icsRun, TransactionType::Expense, '2026-03-0'.($i + 4), $amount, 'nsa-merchant-'.$suffix.'-'.$i);
    }

    $marchId = (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'import_run_id' => $icsRun->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => $total,
        'open_balance_minor' => abs($total),
        'currency' => 'EUR',
        'state' => CardStatementState::Open->value,
        'created_at' => '2026-04-01 12:00:00',
        'updated_at' => '2026-04-01 12:00:00',
    ]);

    nsaTx($db, $user, $payerTwo, $payerRun, TransactionType::TransferOut, '2026-03-29', $total, 'nsa-ics-settle-'.$suffix, ['counterparty_iban' => NSA_ICS_IBAN]);

    return ['user' => $user, 'card' => $card, 'payerOne' => $payerOne, 'payerTwo' => $payerTwo, 'marchId' => $marchId];
}

function nsaAccount(User $user, string $slug, AccountKind $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'nsa '.$slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function nsaRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/nsa.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nsaTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, TransactionType $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
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

function nsaOpenAprilStatement(DatabaseManager $db, User $user, Account $card): int
{
    return (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'import_run_id' => null,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 52347,
        'currency' => 'EUR',
        'state' => CardStatementState::Open->value,
        'created_at' => '2026-05-01 12:00:00',
        'updated_at' => '2026-05-01 12:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-05 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    $this->query = $query;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names the account the resolver actually settled the last statement from', function (): void {
    $f = nsaFixture($this->db, '1');

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($f['user']);

    expect((string) $this->db->connection()->table('card_statements')
        ->where('id', $f['marchId'])
        ->value('state'))->toBe(CardStatementState::Settled->value);

    nsaOpenAprilStatement($this->db, $f['user'], $f['card']);

    $dto = $this->query->nextSettlementForUser($f['user']);

    expect($dto?->accountId)->toBe($f['payerTwo']->id);
});

it('never names the card being settled as the account that will settle it', function (): void {
    $f = nsaFixture($this->db, '2');

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($f['user']);

    // A refund that posted inside the now-closed period chains back to its
    // original charge — both legs sit on the CARD, and that link is not a
    // settlement by anybody.
    nsaTx($this->db, $f['user'], $f['card'], nsaRun($f['user'], 'nsar2', 'ics-pdf'), TransactionType::Refund, '2026-03-20', 2001, 'nsa-merchant-2-1');
    $resolver->resolveForUser($f['user']);

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $f['user']->id)
        ->whereIn('from_transaction_id', function ($q) use ($f): void {
            $q->from('transactions')->select('id')->where('account_id', $f['card']->id);
        })
        ->count())->toBeGreaterThan(0);

    nsaOpenAprilStatement($this->db, $f['user'], $f['card']);

    $dto = $this->query->nextSettlementForUser($f['user']);

    expect($dto?->accountId)->not->toBe($f['card']->id)
        ->and($dto?->accountId)->toBe($f['payerTwo']->id);
});
