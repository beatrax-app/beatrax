<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

const SWA_ICS_IBAN = 'NL08ABNA0526650664';

const SWA_EXPENSES = 3;

/**
 * The same statement, settled once from an account of the given kind. The
 * resolver used to require `bank` here, so every other kind of payer was
 * invisible to the pass and its statement never left `open`.
 *
 * @return array{user: User, statementId: int}
 */
function swaFixture(DatabaseManager $db, AccountKind $payerKind, string $suffix): array
{
    $user = User::query()->create([
        'username' => 'swa-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $card = swaAccount($user, 'swa-ics-'.$suffix, AccountKind::IcsCard, 'SWA-ICS-CARD-'.$suffix);
    $payer = swaAccount($user, 'swa-payer-'.$suffix, $payerKind, 'NL57ASNB01234567'.$suffix);

    $icsRun = swaRun($user, 'swai'.$suffix, 'ics-pdf');
    $payerRun = swaRun($user, 'swap'.$suffix, 'asn-csv');

    $total = 0;
    for ($i = 1; $i <= SWA_EXPENSES; $i++) {
        $amount = -(2000 + $i);
        $total += $amount;
        swaTx($db, $user, $card, $icsRun, TransactionType::Expense, '2026-03-'.str_pad((string) ($i + 4), 2, '0', STR_PAD_LEFT), $amount, 'swa-merchant-'.$suffix.'-'.$i);
    }

    $statementId = (int) $db->connection()->table('card_statements')->insertGetId([
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

    swaTx($db, $user, $payer, $payerRun, TransactionType::TransferOut, '2026-03-29', $total, 'swa-ics-settle-'.$suffix, ['counterparty_iban' => SWA_ICS_IBAN]);

    return ['user' => $user, 'statementId' => $statementId];
}

function swaAccount(User $user, string $slug, AccountKind $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'swa '.$slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function swaRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/swa.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function swaTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, TransactionType $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
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

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-04-05 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('settles a card statement paid from an account that is not a bank', function (AccountKind $payerKind, string $suffix): void {
    $fixture = swaFixture($this->db, $payerKind, $suffix);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($fixture['user']);

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $fixture['user']->id)
        ->where('kind', ChainLinkKind::IcsBulkSettle->value)
        ->where('state', ChainLinkState::Confirmed->value)
        ->count())->toBe(SWA_EXPENSES);

    expect((string) $this->db->connection()->table('card_statements')
        ->where('id', $fixture['statementId'])
        ->value('state'))->toBe(CardStatementState::Settled->value);
})->with([
    'paypal balance' => [AccountKind::Paypal, '1'],
    'cash' => [AccountKind::Cash, '2'],
]);

it('never treats the card being settled as the account that settled it', function (): void {
    $fixture = swaFixture($this->db, AccountKind::IcsCard, '3');

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($fixture['user']);

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $fixture['user']->id)
        ->count())->toBe(0);
});
