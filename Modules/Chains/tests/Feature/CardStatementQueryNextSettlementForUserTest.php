<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\NextSettlementDto;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

function nsmUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function nsmIcsAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS '.$slug,
        'slug' => $slug,
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD-'.bin2hex(random_bytes(3)),
        'default_currency' => 'EUR',
    ]);
}

function nsmAsnAccount(User $user, string $slug, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function nsmImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nsm-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => str_repeat(bin2hex(random_bytes(1)), 32),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function nsmTransaction(User $user, Account $account, ImportRun $run, string $tag, int $amountMinor): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $amountMinor < 0 ? 'expense' : 'income',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'nsm '.$tag,
        'counterparty_normalized' => 'nsm-'.strtolower($tag),
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => abs($amountMinor) % 100000,
        'fingerprint' => str_pad('nsm-'.$tag.'-'.$account->id.'-'.$amountMinor, 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function nsmChainLink(
    DatabaseManager $db,
    User $user,
    Transaction $fundedTx,
    Transaction $funderTx,
    string $createdAt,
): void {
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fundedTx->id,
        'to_transaction_id' => $funderTx->id,
        'kind' => 'ics_bulk_settle',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['source' => 'fixture'], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    $this->query = $query;
});

it('returns null when no open card_statement exists', function (): void {
    $user = nsmUser('nsm-no-open');
    nsmAsnAccount($user, 'nsm-asn-no-open', 'NL33ASNB1234567001');
    $ics = nsmIcsAccount($user, 'nsm-ics-no-open');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 0,
        'state' => 'settled',
    ]);

    expect($this->query->nextSettlementForUser($user))->toBeNull();
});

it('returns a DTO with funder=ASN when an open card_statement + historical settlement exist', function (): void {
    $user = nsmUser('nsm-funder');
    $asn = nsmAsnAccount($user, 'nsm-asn-funder', 'NL06ASNB1234567002');
    $ics = nsmIcsAccount($user, 'nsm-ics-funder');
    $run = nsmImportRun($user);

    $historicalIcsExpense = nsmTransaction($user, $ics, $run, 'hist-ics', -1500);
    $historicalAsnFunder = nsmTransaction($user, $asn, $run, 'hist-asn', -1500);
    nsmChainLink($this->db, $user, $historicalIcsExpense, $historicalAsnFunder, '2026-04-15 12:00:00');

    $openStatement = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 52347,
        'state' => 'open',
    ]);

    $dto = $this->query->nextSettlementForUser($user);

    expect($dto)->toBeInstanceOf(NextSettlementDto::class);
    expect($dto?->accountId)->toBe($asn->id);
    expect($dto?->statementId)->toBe((int) $openStatement->id);
    expect($dto?->amount->toMinor())->toBe(52347);
    expect($dto?->amount->currency())->toBe('EUR');
    expect($dto?->dueDate->format('Y-m-d'))->toBe('2026-05-05');
    expect($dto?->state)->toBe('open');
});

it('states the amount in the currency the statement was stored in', function (): void {
    // The read site used to hardcode EUR, which was right only for as long as
    // every stored row happened to be EUR.
    $user = nsmUser('nsm-currency');
    $asn = nsmAsnAccount($user, 'nsm-asn-currency', 'NL06ASNB1234567099');
    $ics = nsmIcsAccount($user, 'nsm-ics-currency');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -4200,
        'open_balance_minor' => 4200,
        'currency' => 'USD',
        'state' => 'open',
    ]);

    $dto = $this->query->nextSettlementForUser($user);

    expect($dto?->amount->currency())->toBe('USD');
    expect($dto?->accountId)->toBe($asn->id);
});

it('falls back to the user\'s first ASN account when no historical settlement exists', function (): void {
    $user = nsmUser('nsm-fallback');
    $asn = nsmAsnAccount($user, 'nsm-asn-fallback', 'NL76ASNB1234567003');
    $ics = nsmIcsAccount($user, 'nsm-ics-fallback');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    $dto = $this->query->nextSettlementForUser($user);

    expect($dto)->toBeInstanceOf(NextSettlementDto::class);
    expect($dto?->accountId)->toBe($asn->id);
});

it('returns null when the user has zero ASN accounts (graceful degradation)', function (): void {
    $user = nsmUser('nsm-no-asn');
    $ics = nsmIcsAccount($user, 'nsm-ics-no-asn');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    expect($this->query->nextSettlementForUser($user))->toBeNull();
});

it('isolates by user — user B\'s open statement never leaks to user A', function (): void {
    $userA = nsmUser('nsm-iso-a');
    $userB = nsmUser('nsm-iso-b');

    nsmAsnAccount($userB, 'nsm-asn-iso-b', 'NL49ASNB1234567004');
    $icsB = nsmIcsAccount($userB, 'nsm-ics-iso-b');
    $runB = nsmImportRun($userB);
    CardStatement::query()->create([
        'user_id' => $userB->id,
        'account_id' => $icsB->id,
        'import_run_id' => $runB->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    expect($this->query->nextSettlementForUser($userA))->toBeNull();
});

it('picks the most-recent open statement when multiple are open', function (): void {
    $user = nsmUser('nsm-recent');
    nsmAsnAccount($user, 'nsm-asn-recent', 'NL22ASNB1234567005');
    $ics = nsmIcsAccount($user, 'nsm-ics-recent');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => -1000,
        'open_balance_minor' => 1000,
        'state' => 'open',
    ]);
    $newer = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -2000,
        'open_balance_minor' => 2000,
        'state' => 'open',
    ]);

    $dto = $this->query->nextSettlementForUser($user);
    expect($dto?->statementId)->toBe((int) $newer->id);
    expect($dto?->dueDate->format('Y-m-d'))->toBe('2026-05-05');
});

it('includes partially_settled state and surfaces it on the DTO', function (): void {
    $user = nsmUser('nsm-partial');
    nsmAsnAccount($user, 'nsm-asn-partial', 'NL92ASNB1234567006');
    $ics = nsmIcsAccount($user, 'nsm-ics-partial');
    $run = nsmImportRun($user);

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => -5000,
        'open_balance_minor' => 3000,
        'state' => 'open',
    ]);
    $partial = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -7000,
        'open_balance_minor' => 4000,
        'state' => 'partially_settled',
    ]);

    $dto = $this->query->nextSettlementForUser($user);
    expect($dto?->state)->toBe('partially_settled');
    expect($dto?->statementId)->toBe((int) $partial->id);
    expect($dto?->amount->toMinor())->toBe(4000);
});

it('funder resolution picks the most-recent chain_link when multiple historical settlements exist', function (): void {
    $user = nsmUser('nsm-multi-funder');
    $asnOld = nsmAsnAccount($user, 'nsm-asn-old', 'NL65ASNB1234567007');
    $asnNew = nsmAsnAccount($user, 'nsm-asn-new', 'NL38ASNB1234567008');
    $ics = nsmIcsAccount($user, 'nsm-ics-multi');
    $run = nsmImportRun($user);

    $oldIcsExpense = nsmTransaction($user, $ics, $run, 'old-ics', -2500);
    $oldAsnFunder = nsmTransaction($user, $asnOld, $run, 'old-asn', -2500);
    nsmChainLink($this->db, $user, $oldIcsExpense, $oldAsnFunder, '2026-02-15 12:00:00');

    $newIcsExpense = nsmTransaction($user, $ics, $run, 'new-ics', -3500);
    $newAsnFunder = nsmTransaction($user, $asnNew, $run, 'new-asn', -3500);
    nsmChainLink($this->db, $user, $newIcsExpense, $newAsnFunder, '2026-04-15 12:00:00');

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -20000,
        'open_balance_minor' => 20000,
        'state' => 'open',
    ]);

    $dto = $this->query->nextSettlementForUser($user);
    expect($dto?->accountId)->toBe($asnNew->id);
});

it('openForAccount still returns the open statement for the account it is given', function (): void {
    $user = nsmUser('nsm-open-for-account');
    nsmAsnAccount($user, 'nsm-asn-ofa', 'NL11ASNB1234567009');
    $ics = nsmIcsAccount($user, 'nsm-ics-ofa');
    $run = nsmImportRun($user);

    $stmt = CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -5000,
        'open_balance_minor' => 5000,
        'state' => 'open',
    ]);

    $byAccount = $this->query->openForAccount($ics->id, $user);
    expect($byAccount)->not->toBeNull();
    expect($byAccount?->id)->toBe((int) $stmt->id);
});
