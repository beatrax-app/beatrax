<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'chain-schema',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->otherUser = User::query()->create([
        'username' => 'chain-schema-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asnAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN schema test',
        'slug' => 'cs-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->icsAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS schema test',
        'slug' => 'cs-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cs.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->fromTx = makeChainTx($this->user, $this->asnAccount, $this->run, 1);
    $this->toTx = makeChainTx($this->user, $this->icsAccount, $this->run, 2);
});

function makeChainTx(User $user, Account $account, ImportRun $run, int $index): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1500 - $index,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500 - $index,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Schema Test '.$index,
        'counterparty_normalized' => 'schema-test-'.$index,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $index,
        'fingerprint' => str_pad((string) $index, 64, 's', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

it('creates the three Wave 1 tables with the documented column lists', function (): void {
    expect(Schema::hasTable('chain_links'))->toBeTrue();
    expect(Schema::hasTable('card_statements'))->toBeTrue();
    expect(Schema::hasTable('card_statement_credits'))->toBeTrue();
    expect(Schema::hasTable('chain_resolution_runs'))->toBeTrue();

    $chainLinkCols = Schema::getColumnListing('chain_links');
    foreach ([
        'id', 'user_id', 'from_transaction_id', 'to_transaction_id',
        'kind', 'state', 'confidence', 'resolver', 'evidence',
        'created_at', 'updated_at',
    ] as $col) {
        expect($chainLinkCols)->toContain($col);
    }

    $cardStatementCols = Schema::getColumnListing('card_statements');
    foreach ([
        'id', 'user_id', 'account_id', 'import_run_id',
        'period_start', 'period_end',
        'total_amount_minor', 'open_balance_minor', 'currency', 'state',
        'created_at', 'updated_at',
    ] as $col) {
        expect($cardStatementCols)->toContain($col);
    }

    $creditCols = Schema::getColumnListing('card_statement_credits');
    foreach ([
        'id', 'user_id', 'from_statement_id', 'to_statement_id',
        'amount_minor', 'currency', 'reason', 'created_at', 'updated_at',
    ] as $col) {
        expect($creditCols)->toContain($col);
    }
});

it('happy-path inserts a chain_link with valid enum values', function (): void {
    $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => $this->toTx->id,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.0,
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'happy-path']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);
    expect($this->conn->table('chain_links')->count())->toBe(1);
});

it('rejects a chain_link with an invalid kind via the BEFORE INSERT trigger', function (): void {
    expect(fn () => $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => $this->toTx->id,
        'kind' => 'unknown_kind',
        'state' => 'candidate',
        'confidence' => 0.5,
        'resolver' => 'auto',
        'evidence' => json_encode([]),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'Invalid chain_links.kind value');
});

it('rejects a chain_link with an invalid state via the BEFORE INSERT trigger', function (): void {
    expect(fn () => $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => $this->toTx->id,
        'kind' => 'paypal_funding',
        'state' => 'maybe',
        'confidence' => 0.5,
        'resolver' => 'auto',
        'evidence' => json_encode([]),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'Invalid chain_links.state value');
});

it('rejects a card_statements row with an invalid state', function (): void {
    expect(fn () => $this->conn->table('card_statements')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'dunno',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'Invalid card_statements.state value');
});

it('rejects a card_statement_credits row with an invalid reason', function (): void {
    $statementId = (int) $this->conn->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    expect(fn () => $this->conn->table('card_statement_credits')->insert([
        'user_id' => $this->user->id,
        'from_statement_id' => $statementId,
        'to_statement_id' => null,
        'amount_minor' => 153,
        'reason' => 'discount',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'Invalid card_statement_credits.reason value');
});

it('blocks duplicate card_statements rows via the UNIQUE constraint', function (): void {
    $row = [
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ];
    $this->conn->table('card_statements')->insert($row);
    expect(fn () => $this->conn->table('card_statements')->insert($row))
        ->toThrow(QueryException::class);
});

it('cascades chain_links rows when their from_transaction is deleted', function (): void {
    $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => $this->toTx->id,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.0,
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'cascade-test']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);
    expect($this->conn->table('chain_links')->count())->toBe(1);

    $this->conn->table('transactions')->where('id', $this->fromTx->id)->delete();
    expect($this->conn->table('chain_links')->count())->toBe(0);
});

it('cascades card_statement_credits when from_statement is deleted and nulls to_statement on delete', function (): void {
    $idA = (int) $this->conn->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);
    $idB = (int) $this->conn->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-05-15 00:00:00',
        'period_end' => '2026-06-14 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    $creditId = (int) $this->conn->table('card_statement_credits')->insertGetId([
        'user_id' => $this->user->id,
        'from_statement_id' => $idA,
        'to_statement_id' => $idB,
        'amount_minor' => 153,
        'reason' => 'overpayment',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    $this->conn->table('card_statements')->where('id', $idB)->delete();
    $survivor = $this->conn->table('card_statement_credits')->where('id', $creditId)->first(['to_statement_id', 'from_statement_id']);
    expect($survivor)->not->toBeNull();
    expect($survivor->to_statement_id)->toBeNull();
    expect((int) $survivor->from_statement_id)->toBe($idA);

    $this->conn->table('card_statements')->where('id', $idA)->delete();
    expect($this->conn->table('card_statement_credits')->where('id', $creditId)->count())->toBe(0);
});

it('permits a NULL to_transaction_id for exceeded-tolerance ics_bulk_settle candidates', function (): void {
    $statementId = (int) $this->conn->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => 0.7,
        'resolver' => 'auto',
        'evidence' => json_encode([
            'statement_id' => $statementId,
            'tolerance_used' => 'exceeded',
            'unaccounted_delta_minor' => 1234,
        ]),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    expect($this->conn->table('chain_links')->whereNull('to_transaction_id')->count())->toBe(1);
});

it('rejects a NULL to_transaction_id when kind is paypal_funding', function (): void {
    expect(fn () => $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => null,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => 0.7,
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'exceeded']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates');
});

it('rejects a NULL to_transaction_id when state is confirmed', function (): void {
    expect(fn () => $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'confirmed',
        'confidence' => 1.0,
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'exceeded']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates');
});

it('rejects a NULL to_transaction_id when evidence.tolerance_used is not exceeded', function (): void {
    expect(fn () => $this->conn->table('chain_links')->insert([
        'user_id' => $this->user->id,
        'from_transaction_id' => $this->fromTx->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => 0.7,
        'resolver' => 'auto',
        'evidence' => json_encode(['tolerance_used' => 'amount_5eur']),
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates');
});
