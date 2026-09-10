<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The reader's own words about a row were the one thing the search box could
// not reach: transactions.note and transaction_splits.note were sealed and
// indexed by nothing, so a transaction the reader had annotated answered "no
// transactions match" to the very sentence they had typed onto it.

function noteSearchCount(int $userId, string $needle): int
{
    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    return app(SearchQuery::class)->search($user, $needle, SearchFilters::empty())->totalCount;
}

function noteSearchCategory(string $suffix): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Office '.$suffix,
        'slug' => 'note-search-office-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('finds a transaction by the note the reader wrote on it', function (): void {
    $userId = $this->searchTestUser('tx-note-'.bin2hex(random_bytes(4)));
    $txId = $this->searchTestTransaction($userId);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    app(SetsTransactionNote::class)($txId, 'Kapotte waterkoker vervangen', NoteMode::Set->value, $user);

    expect(noteSearchCount($userId, 'waterkoker'))->toBe(1);
});

it('finds a transaction by the note on one of its split legs', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $userId = $this->searchTestUser('leg-note-'.$suffix);
    $txId = $this->searchTestTransaction($userId);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $categoryId = noteSearchCategory($suffix);

    app(SavesTransactionSplit::class)->save($user, $txId, [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2000, 'note' => 'Statiegeld flessen'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2990, 'note' => null],
    ]);

    expect(noteSearchCount($userId, 'statiegeld'))->toBe(1);
});

it('finds it by the words on every leg, not only the first', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $userId = $this->searchTestUser('legs-note-'.$suffix);
    $txId = $this->searchTestTransaction($userId);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $categoryId = noteSearchCategory($suffix);

    app(SavesTransactionSplit::class)->save($user, $txId, [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -1000, 'note' => 'Statiegeld flessen'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -1990, 'note' => 'Kilo appelstroop'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2000, 'note' => 'Vier bossen tulpen'],
    ]);

    expect(noteSearchCount($userId, 'statiegeld'))->toBe(1);
    expect(noteSearchCount($userId, 'appelstroop'))->toBe(1);
    expect(noteSearchCount($userId, 'tulpen'))->toBe(1);
});

// The two writers of one body have disagreed before, and a rebuild is where it
// showed: whatever search:reindex composes has to be what the incremental
// writer composed, leg for leg.
it('still finds every note after a full reindex', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $userId = $this->searchTestUser('reindex-note-'.$suffix);
    $txId = $this->searchTestTransaction($userId);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $categoryId = noteSearchCategory($suffix);

    app(SetsTransactionNote::class)($txId, 'Kapotte waterkoker vervangen', NoteMode::Set->value, $user);
    app(SavesTransactionSplit::class)->save($user, $txId, [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2000, 'note' => 'Statiegeld flessen'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2990, 'note' => 'Kilo appelstroop'],
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $before = $db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->value('search_body');

    Artisan::call('search:reindex');

    $after = $db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->value('search_body');

    expect($after)->toBe($before);
    expect(noteSearchCount($userId, 'waterkoker'))->toBe(1);
    expect(noteSearchCount($userId, 'statiegeld'))->toBe(1);
    expect(noteSearchCount($userId, 'appelstroop'))->toBe(1);
});

it('drops a leg note from the body when the leg is removed', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $userId = $this->searchTestUser('drop-leg-'.$suffix);
    $txId = $this->searchTestTransaction($userId);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $categoryId = noteSearchCategory($suffix);

    $splits = app(SavesTransactionSplit::class);
    $splits->save($user, $txId, [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2000, 'note' => 'Statiegeld flessen'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2990, 'note' => 'Kilo appelstroop'],
    ]);

    $splits->unsplit($user, $txId, $categoryId);

    expect(noteSearchCount($userId, 'statiegeld'))->toBe(0);
    expect(noteSearchCount($userId, 'appelstroop'))->toBe(0);
});

// A note already on disk is not rebuilt by anything: no later write touches
// the row, a sync does not, and search:reindex cannot on an encrypted desktop
// where a console run holds no app-lock key.
const NOTE_BACKFILL_MIGRATION = 'Modules/Search/Database/Migrations/2026_09_10_000001_index_the_notes_written_before_the_index_read_them.php';

it('rebuilds a body for notes written before the index read them at all', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $userId = $this->searchTestUser('backfill-note-'.$suffix);
    $txId = $this->searchTestTransaction($userId);
    $categoryId = noteSearchCategory($suffix);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Raw writes, because that is the state on disk: the rows were written by
    // a version of these writers that told the index nothing.
    $connection->table('transactions')->where('id', $txId)->update(['note' => 'Kapotte waterkoker vervangen']);
    $connection->table('transaction_splits')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'note' => 'Statiegeld flessen',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(noteSearchCount($userId, 'waterkoker'))->toBe(0);
    expect(noteSearchCount($userId, 'statiegeld'))->toBe(0);

    /** @var object{up: callable} $migration */
    $migration = require base_path(NOTE_BACKFILL_MIGRATION);
    $migration->up();

    expect(noteSearchCount($userId, 'waterkoker'))->toBe(1);
    expect(noteSearchCount($userId, 'statiegeld'))->toBe(1);
});

// A note is judged by the rule the columns beside it are judged by: handed
// ciphertext no epoch in this keyring opens, the codec answers with the empty
// string, and a body built from that answer would overwrite the words with
// nothing over a ledger that still holds them.
it('refuses the whole document rather than indexing a note it cannot read', function (): void {
    $suffix = bin2hex(random_bytes(4));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $userId = $this->searchTestUser('sealed-note-'.$suffix);
    $txId = $this->searchTestTransaction($userId);
    $categoryId = noteSearchCategory($suffix);

    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    app(SetsTransactionNote::class)($txId, 'Kapotte waterkoker vervangen', NoteMode::Set->value, $user);
    app(SavesTransactionSplit::class)->save($user, $txId, [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2000, 'note' => 'Statiegeld flessen'],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -2990, 'note' => null],
    ]);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();
    $connection->table('transaction_search_docs')->where('transaction_id', $txId)->delete();

    AppLockTestHarness::lock($session);
    app(SearchIndexWriterContract::class)->upsertForTransaction($txId, $userId);

    expect($connection->table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(0);
    expect($connection->table('search_index_repairs')->where('transaction_id', $txId)->count())->toBe(1);

    AppLockTestHarness::unlock(app(Session::class), str_repeat("\x2a", 32));
    app(SearchIndexWriterContract::class)->upsertForTransaction($txId, $userId);

    expect(noteSearchCount($userId, 'waterkoker'))->toBe(1);
    expect(noteSearchCount($userId, 'statiegeld'))->toBe(1);
});
