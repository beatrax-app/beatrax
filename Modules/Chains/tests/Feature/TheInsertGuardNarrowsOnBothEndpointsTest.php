<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

function guardTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $key): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::Expense->value,
        'posted_at' => '2026-03-10',
        'booked_at' => '2026-03-10 12:00:00',
        'value_date' => '2026-03-10',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Guard '.$key,
        'counterparty_normalized' => 'guard-'.$key,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($key) % 100000,
        'fingerprint' => hash('sha256', 'guard-'.$key),
        'fingerprint_version' => 3,
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function guardRow(int $fromId, ?int $toId, ChainLinkKind $kind, array $overrides = []): array
{
    return array_merge([
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind->value,
        'state' => ChainLinkState::Confirmed->value,
        'confidence' => '1.000',
        'resolver' => ChainLinkResolver::Auto->value,
        'evidence' => ['signature_hash' => 'guard-sig'],
    ], $overrides);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-04-01 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'insert-guard',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'guard bank',
        'slug' => 'guard-bank',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/guard.csv',
        'sha256' => str_repeat('g', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->settlement = guardTx($this->db, $this->user, $account, $run, 'settlement');
    $this->legOne = guardTx($this->db, $this->user, $account, $run, 'leg-one');
    $this->legTwo = guardTx($this->db, $this->user, $account, $run, 'leg-two');

    /** @var ChainLinkInsertHelper $helper */
    $helper = $this->app->make(ChainLinkInsertHelper::class);
    $this->helper = $helper;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// One settlement fans out to every charge it covered, so the `from` end
// repeats by design. A guard reading only (user, from, kind) calls the second
// charge a duplicate of the first and drops the rest of the statement.
it('keeps a second leg of the same settlement', function (): void {
    expect($this->helper->insertIfNotExists(
        guardRow($this->settlement, $this->legOne, ChainLinkKind::IcsBulkSettle),
        $this->user->id,
    ))->toBeTrue();

    expect($this->helper->insertIfNotExists(
        guardRow($this->settlement, $this->legTwo, ChainLinkKind::IcsBulkSettle),
        $this->user->id,
    ))->toBeTrue();

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $this->user->id)
        ->count())->toBe(2);
});

it('refuses the same pair twice, in any state', function (): void {
    $this->helper->insertIfNotExists(
        guardRow($this->settlement, $this->legOne, ChainLinkKind::IcsBulkSettle, ['state' => ChainLinkState::Rejected->value]),
        $this->user->id,
    );

    expect($this->helper->insertIfNotExists(
        guardRow($this->settlement, $this->legOne, ChainLinkKind::IcsBulkSettle),
        $this->user->id,
    ))->toBeFalse();

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $this->user->id)
        ->count())->toBe(1);
});

it('binds a NULL-endpoint hint once per from-row and kind', function (): void {
    expect($this->helper->insertIfNotExists(
        guardRow($this->settlement, null, ChainLinkKind::FundedByCardHint, ['state' => ChainLinkState::Candidate->value, 'confidence' => '0.500']),
        $this->user->id,
    ))->toBeTrue();

    expect($this->helper->insertIfNotExists(
        guardRow($this->settlement, null, ChainLinkKind::FundedByCardHint, ['state' => ChainLinkState::Candidate->value, 'confidence' => '0.500']),
        $this->user->id,
    ))->toBeFalse();

    expect($this->db->connection()->table('chain_links')
        ->where('user_id', $this->user->id)
        ->count())->toBe(1);
});
