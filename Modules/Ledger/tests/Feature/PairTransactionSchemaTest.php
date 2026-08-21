<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Tests\TestCase;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('declares pair_transaction_id as a nullable self-FK with ON DELETE SET NULL', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;

    /** @var list<object{table: string, from: string, to: string, on_delete: string}> $foreignKeys */
    $foreignKeys = $db->connection()
        ->select('PRAGMA foreign_key_list(transactions)');

    $match = null;
    foreach ($foreignKeys as $fk) {
        if (($fk->from ?? null) === 'pair_transaction_id') {
            $match = $fk;
            break;
        }
    }

    expect($match)->not->toBeNull();
    expect($match->table ?? null)->toBe('transactions');
    expect($match->to ?? null)->toBe('id');
    expect(strtoupper((string) ($match->on_delete ?? '')))->toBe('SET NULL');
});

it('defaults pair_transaction_id to NULL when a row is inserted without it', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;

    $row = $db->connection()
        ->selectOne("SELECT dflt_value FROM pragma_table_info('transactions') WHERE name='pair_transaction_id'");

    expect($row)->not->toBeNull();
    expect($row->dflt_value ?? null)->toBeNull();
});

it('resets the surviving row\'s pair_transaction_id to NULL when its partner is deleted', function (): void {
    [$user, $asn, $ics, $run] = pairSchemaFixture($this);

    $a = Transaction::create(pairSchemaRow($user, $asn, $run, [
        'amount_minor' => -12345,
        'settled_amount_minor' => -12345,
        'type' => 'transfer_out',
        'counterparty_iban' => 'ICS-CARD',
        'source_row_index' => 1,
        'fingerprint' => str_repeat('a', 64),
    ]));
    $b = Transaction::create(pairSchemaRow($user, $ics, $run, [
        'amount_minor' => 12345,
        'settled_amount_minor' => 12345,
        'type' => 'transfer_in',
        'counterparty_iban' => 'NL57ASNB0123456789',
        'source_row_index' => 2,
        'fingerprint' => str_repeat('b', 64),
    ]));

    $a->pair_transaction_id = $b->id;
    $a->save();
    $b->pair_transaction_id = $a->id;
    $b->save();

    $b->delete();

    /** @var Transaction $reloaded */
    $reloaded = Transaction::query()->findOrFail($a->id);
    expect($reloaded->pair_transaction_id)->toBeNull();
});

it('creates the transactions_unpaired_transfer_idx partial index over the listener hot-path columns', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;

    $index = $db->connection()
        ->selectOne("SELECT sql FROM sqlite_master WHERE type='index' AND name='transactions_unpaired_transfer_idx'");

    expect($index)->not->toBeNull();
    $sql = (string) ($index->sql ?? '');
    expect($sql)->toContain('user_id');
    expect($sql)->toContain('account_id');
    expect($sql)->toContain('booked_at');
    expect($sql)->toContain('pair_transaction_id IS NULL');
    expect($sql)->toContain('transfer_out');
    expect($sql)->toContain('transfer_in');
});

it('exposes Transaction::pair() BelongsTo returning the partner when paired and null otherwise', function (): void {
    [$user, $asn, $ics, $run] = pairSchemaFixture($this);

    $a = Transaction::create(pairSchemaRow($user, $asn, $run, [
        'amount_minor' => -500,
        'settled_amount_minor' => -500,
        'type' => 'transfer_out',
        'source_row_index' => 1,
        'fingerprint' => str_repeat('c', 64),
    ]));
    $b = Transaction::create(pairSchemaRow($user, $ics, $run, [
        'amount_minor' => 500,
        'settled_amount_minor' => 500,
        'type' => 'transfer_in',
        'source_row_index' => 2,
        'fingerprint' => str_repeat('d', 64),
    ]));

    expect($a->pair)->toBeNull();

    $a->pair_transaction_id = $b->id;
    $a->save();
    $b->pair_transaction_id = $a->id;
    $b->save();

    /** @var Transaction $reloaded */
    $reloaded = Transaction::query()->findOrFail($a->id);
    expect($reloaded->pair)->not->toBeNull();
    expect($reloaded->pair->id)->toBe($b->id);
});

it('returns a NEW CanonicalTransaction instance from withType() preserving every other field', function (): void {
    $tx = new CanonicalTransaction(
        userId: 1,
        accountId: 2,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: CarbonImmutable::parse('2026-05-15 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: -1299,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'AH Amsterdam',
        counterpartyIban: 'NL57ASNB0123456789',
        counterpartyNormalized: 'ah amsterdam',
        normalizationVersion: 1,
        description: 'groceries',
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 5,
        sourceRowIndex: 7,
        sourceRef: 'REF-7',
        rawPayload: ['format' => 'asn-csv'],
    );

    $flipped = $tx->withType('transfer_out');

    expect($flipped)->not->toBe($tx);
    expect($flipped)->toBeInstanceOf(CanonicalTransaction::class);
    expect($flipped->type)->toBe('transfer_out');
    expect($tx->type)->toBe('expense');
    expect($flipped->userId)->toBe(1);
    expect($flipped->accountId)->toBe(2);
    expect($flipped->amountMinor)->toBe(-1299);
    expect($flipped->currency)->toBe('EUR');
    expect($flipped->settledAmountMinor)->toBe(-1299);
    expect($flipped->settledCurrency)->toBe('EUR');
    expect($flipped->counterpartyName)->toBe('AH Amsterdam');
    expect($flipped->counterpartyIban)->toBe('NL57ASNB0123456789');
    expect($flipped->counterpartyNormalized)->toBe('ah amsterdam');
    expect($flipped->normalizationVersion)->toBe(1);
    expect($flipped->description)->toBe('groceries');
    expect($flipped->sourceFormat)->toBe('asn-csv');
    expect($flipped->importRunId)->toBe(5);
    expect($flipped->sourceRowIndex)->toBe(7);
    expect($flipped->sourceRef)->toBe('REF-7');
    expect($flipped->rawPayload)->toBe(['format' => 'asn-csv']);
});

/**
 * @return array{0: User, 1: Account, 2: Account, 3: ImportRun}
 */
function pairSchemaFixture(TestCase $testCase): array
{
    $fixture = $testCase->seedFixtureUserAndAccount();
    $user = $fixture['user'];
    $asn = $fixture['account'];
    $ics = $fixture['icsAccount'];

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pair-schema-fixture.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    return [$user, $asn, $ics, $run];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pairSchemaRow(
    User $user,
    Account $account,
    ImportRun $run,
    array $overrides = [],
): array {
    return array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -100,
        'currency' => 'EUR',
        'settled_amount_minor' => -100,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Partner',
        'counterparty_normalized' => 'partner',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => str_pad((string) random_int(1, 999999), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides);
}
