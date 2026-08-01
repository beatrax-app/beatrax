<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Modules\Tax\Public\Actions\TagTransaction;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * 14.1-02 — CR-01/CR-02/RESEARCH-gap: TagTransaction (tax_transaction_tags.note),
 * SaveTransactionSplit (transaction_splits.note), and SetTransactionNote
 * (transactions.note) all encrypt on write under an encrypted user, per D-07.
 * Every test that asserts ciphertext primes the user with
 * EnablesEncryptionForUser so a decrypt-of-plaintext no-op can never mask a
 * broken write path.
 */

function nweUser(): User
{
    return User::query()->create([
        'username' => 'nwe-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function nweAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN nwe',
        'slug' => 'nwe-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function nweImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/nwe.xml',
        'sha256' => hash('sha256', 'nwe-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
}

function nweTransaction(User $user, Account $account, ImportRun $run, int $rowIndex, int $settledMinor = -1000): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'NWE Vendor',
        'counterparty_normalized' => 'nwe vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('nwe-'.$rowIndex.bin2hex(random_bytes(4)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function nweDeductionCategory(DatabaseManager $db, User $user): int
{
    return (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'NWE Deduction',
        'short_name' => 'nwe',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nweSpendCategory(string $name): Category
{
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

// --- Task 1: TagTransaction (tax_transaction_tags.note, CR-01) ---

it('encrypts tax_transaction_tags.note on INSERT for an encrypted user and decrypts back to the original', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 1);
    $catId = nweDeductionCategory(app(DatabaseManager::class), $user);

    app(TagTransaction::class)->execute($user->id, $tx->id, $catId, 'Deductible groceries', null);

    $row = app(DatabaseManager::class)->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $tx->id)->first();

    expect($row->note)->not->toBe('Deductible groceries');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('tax_transaction_tags', 'note', $row->note, $user->id, $session)['value'])
        ->toBe('Deductible groceries');
});

it('encrypts tax_transaction_tags.note on the updateExisting branch for an encrypted user', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 2);
    $catId = nweDeductionCategory(app(DatabaseManager::class), $user);

    app(TagTransaction::class)->execute($user->id, $tx->id, $catId, 'First note', null);
    app(TagTransaction::class)->execute($user->id, $tx->id, $catId, 'Updated note', null);

    $row = app(DatabaseManager::class)->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $tx->id)->first();

    expect($row->note)->not->toBe('Updated note');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('tax_transaction_tags', 'note', $row->note, $user->id, $session)['value'])
        ->toBe('Updated note');
});

it('stores tax_transaction_tags.note in plaintext for a non-encrypted user (pass-through)', function (): void {
    $user = nweUser();
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 3);
    $catId = nweDeductionCategory(app(DatabaseManager::class), $user);

    app(TagTransaction::class)->execute($user->id, $tx->id, $catId, 'Plaintext note', null);

    $row = app(DatabaseManager::class)->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $tx->id)->first();

    expect($row->note)->toBe('Plaintext note');
});

it('a null tax tag note stores null for an encrypted user, no crash', function (): void {
    $user = nweUser();
    $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 4);
    $catId = nweDeductionCategory(app(DatabaseManager::class), $user);

    app(TagTransaction::class)->execute($user->id, $tx->id, $catId, null, null);

    $row = app(DatabaseManager::class)->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $tx->id)->first();

    expect($row->note)->toBeNull();
});

// --- Task 2: SaveTransactionSplit (transaction_splits.note, CR-02) ---

it('encrypts transaction_splits.note on INSERT for an encrypted user and decrypts back to the original', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 5, -8000);
    $groceries = nweSpendCategory('Groceries');
    $household = nweSpendCategory('Household');

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'weekly shop'],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = app(DatabaseManager::class)->connection()->table('transaction_splits')
        ->where('transaction_id', $tx->id)->orderBy('id')->get();

    $groceriesLeg = $legs->firstWhere('category_id', $groceries->id);
    expect($groceriesLeg->note)->not->toBe('weekly shop');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transaction_splits', 'note', $groceriesLeg->note, $user->id, $session)['value'])
        ->toBe('weekly shop');

    $householdLeg = $legs->firstWhere('category_id', $household->id);
    expect($householdLeg->note)->toBeNull();
});

it('encrypts transaction_splits.note on UPDATE for an encrypted user and decrypts back to the original', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 6, -8000);
    $groceries = nweSpendCategory('Groceries');
    $household = nweSpendCategory('Household');

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $original = app(DatabaseManager::class)->connection()->table('transaction_splits')
        ->where('transaction_id', $tx->id)->orderBy('id')->get();
    $groceriesLegId = (int) $original->firstWhere('category_id', $groceries->id)->id;
    $householdLegId = (int) $original->firstWhere('category_id', $household->id)->id;

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'edited note'],
        ['id' => $householdLegId, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $groceriesLeg = app(DatabaseManager::class)->connection()->table('transaction_splits')
        ->where('id', $groceriesLegId)->first();

    expect($groceriesLeg->note)->not->toBe('edited note');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transaction_splits', 'note', $groceriesLeg->note, $user->id, $session)['value'])
        ->toBe('edited note');
});

it('does not mark note dirty when unchanged, under an encrypted user (decrypt-before-diff)', function (): void {
    $user = nweUser();
    $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 7, -8000);
    $groceries = nweSpendCategory('Groceries');
    $household = nweSpendCategory('Household');

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'unchanging note'],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = app(DatabaseManager::class)->connection()->table('transaction_splits')
        ->where('transaction_id', $tx->id)->orderBy('id')->get();
    $groceriesLegId = (int) $legs->firstWhere('category_id', $groceries->id)->id;
    $householdLegId = (int) $legs->firstWhere('category_id', $household->id)->id;

    Event::fake([TransactionSplitMutated::class]);

    // Re-save with the IDENTICAL note — the stored ciphertext will differ
    // (fresh random nonce) yet the dirty-diff must NOT report a change.
    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'unchanging note'],
        ['id' => $householdLegId, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Event::assertNotDispatched(TransactionSplitMutated::class);
});

it('marks note dirty when genuinely changed, under an encrypted user, with the plaintext value dispatched', function (): void {
    $user = nweUser();
    $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 8, -8000);
    $groceries = nweSpendCategory('Groceries');
    $household = nweSpendCategory('Household');

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'original note'],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = app(DatabaseManager::class)->connection()->table('transaction_splits')
        ->where('transaction_id', $tx->id)->orderBy('id')->get();
    $groceriesLegId = (int) $legs->firstWhere('category_id', $groceries->id)->id;
    $householdLegId = (int) $legs->firstWhere('category_id', $household->id)->id;

    Event::fake([TransactionSplitMutated::class]);

    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'changed note'],
        ['id' => $householdLegId, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Event::assertDispatched(TransactionSplitMutated::class, function (TransactionSplitMutated $event) use ($groceriesLegId): bool {
        return $event->splitId === $groceriesLegId
            && ($event->dirtyFields['note'] ?? null) === 'changed note';
    });
});

// --- Task 3: SetTransactionNote (transactions.note) ---

it('encrypts transactions.note on set for an encrypted user and decrypts back to the original', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 9);

    $affected = (app(SetTransactionNote::class))($tx->id, 'A private note', 'set', $user);
    expect($affected)->toBe(1);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $tx->id)->first();
    expect($row->note)->not->toBe('A private note');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transactions', 'note', $row->note, $user->id, $session)['value'])
        ->toBe('A private note');
});

it('append mode concatenates onto the decrypted existing note and stores ciphertext', function (): void {
    $user = nweUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 10);

    (app(SetTransactionNote::class))($tx->id, 'first line', 'set', $user);
    $affected = (app(SetTransactionNote::class))($tx->id, 'second line', 'append', $user);
    expect($affected)->toBe(1);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $tx->id)->first();

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('transactions', 'note', $row->note, $user->id, $session)['value'])
        ->toBe("first line\nsecond line");
});

it('an unchanged note performs no write, under an encrypted user (decrypt-before-compare)', function (): void {
    $user = nweUser();
    $this->enablesEncryptionForUser($user);
    $account = nweAccount($user);
    $run = nweImportRun($user);
    $tx = nweTransaction($user, $account, $run, 11);

    (app(SetTransactionNote::class))($tx->id, 'stable note', 'set', $user);

    $affected = (app(SetTransactionNote::class))($tx->id, 'stable note', 'set', $user);

    expect($affected)->toBe(0);
});
