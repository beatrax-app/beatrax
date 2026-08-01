<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Core\Public\Contracts\Clock;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Events\TransactionTagged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Feature tests for TAX-01: tag/untag actions with ownership enforcement.
 *
 * Tests the behaviour of TagTransaction and UntagTransaction:
 *   - Idempotent tag/re-tag (unique constraint — updateOrInsert)
 *   - Cross-user transaction → NotFoundHttpException (T-07-01)
 *   - Cross-user category → NotFoundHttpException (T-07-02)
 *   - tax_year_override range validation → InvalidArgumentException (T-07-03)
 *   - TransactionTagged event dispatched on successful tag
 *   - UntagTransaction: silent no-op for missing/cross-user tag
 */

/**
 * Inserts a minimal user row and returns its id.
 */
function taxTestUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts an account + import run + transaction for the given user and
 * returns the transaction id.
 */
function taxTestTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tax ASN '.$suffix,
        'slug' => 'tax-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tax-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tax-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tax-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test-vendor',
        'counterparty_name' => 'Test Vendor BV',
        'normalization_version' => 1,
        'description' => 'Tax test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts a deduction category for the user and returns its id.
 */
function taxTestCategory(DatabaseManager $db, int $userId, string $name = 'Test Category'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => 'TC',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// -----------------------------------------------------------------------

it('tags a transaction for the owner and inserts one row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'tag-owner');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Tax note', null);

    $tag = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect($tag)->not->toBeNull()
        ->and($tag->deduction_category_id)->toBe($catId)
        ->and($tag->note)->toBe('Tax note');
});

it('idempotently re-tags without duplicating the row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'tag-retag');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'First note', null);
    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Updated note', null);

    $count = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->count();

    $tag = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect($count)->toBe(1)
        ->and($tag->note)->toBe('Updated note');
});

it('re-tagging never rewrites created_at — the "first tagged" audit signal survives edits (WR-02)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'tag-created-at');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId, 'Audit Cat');

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'first', null);

    // Backdate created_at so any rewrite is detectable.
    $original = now()->subDays(30)->toDateTimeString();
    $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->update(['created_at' => $original]);

    // Edit the tag (picker save path).
    app(TagTransaction::class)->execute($userId, $txId, $catId, 'edited', null);

    $tag = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first(['created_at', 'note']);

    expect($tag->created_at)->toBe($original)
        ->and($tag->note)->toBe('edited');
});

it('a bare re-tag (all-null payload) preserves the existing category, note, and year override (CR-03)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'tag-bare-retag');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId, 'Curated Category');
    $overrideYear = now()->year - 1;

    // Curated tag: category + note + year override.
    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Curated note', $overrideYear);

    // One-tap re-tag (e.g. a stale ghost Tag button) sends an all-null payload —
    // it must NOT wipe the curated values.
    app(TagTransaction::class)->execute($userId, $txId, null, null, null);

    $tag = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect((int) $tag->deduction_category_id)->toBe($catId)
        ->and($tag->note)->toBe('Curated note')
        ->and((int) $tag->tax_year_override)->toBe($overrideYear);
});

it('throws NotFoundHttpException when the transaction belongs to another user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = taxTestUser($db, 'tx-owner');
    $partner = taxTestUser($db, 'tx-partner');
    $txId = taxTestTransaction($db, $owner);

    expect(fn () => app(TagTransaction::class)->execute($partner, $txId, null, null, null))
        ->toThrow(NotFoundHttpException::class);
});

it('throws NotFoundHttpException when the category belongs to another user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = taxTestUser($db, 'cat-owner');
    $partner = taxTestUser($db, 'cat-partner');
    $txId = taxTestTransaction($db, $partner);
    $catId = taxTestCategory($db, $owner, 'Owner Category');

    expect(fn () => app(TagTransaction::class)->execute($partner, $txId, $catId, null, null))
        ->toThrow(NotFoundHttpException::class);
});

it('throws InvalidArgumentException for tax_year_override outside now±10 years', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'year-override');
    $txId = taxTestTransaction($db, $userId);

    $tooOld = now()->year - 11;
    $tooNew = now()->year + 11;

    expect(fn () => app(TagTransaction::class)->execute($userId, $txId, null, null, $tooOld))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(TagTransaction::class)->execute($userId, $txId, null, null, $tooNew))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a tax_year_override within now±10 years', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'year-valid');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId, 'Valid Year Cat');
    $validYear = now()->year - 5;

    app(TagTransaction::class)->execute($userId, $txId, $catId, null, $validYear);

    $tag = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect($tag->tax_year_override)->toBe($validYear);
});

it('the ±10-year override window follows the injected Clock, not the wall clock (IN-06)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'year-clock-fake');
    $txId = taxTestTransaction($db, $userId);

    // Freeze the Clock at 2030 — valid window becomes 2020..2040.
    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2030, 6, 15));
    app()->instance(Clock::class, $clock);

    /** @var TagTransaction $action */
    $action = app(TagTransaction::class);

    // 2039 would be invalid against the real 2026 wall clock but is valid at 2030.
    $action->execute($userId, $txId, null, null, 2039);

    $stored = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->value('tax_year_override');
    expect((int) $stored)->toBe(2039);

    // 2019 is outside 2030−10.
    expect(fn () => $action->execute($userId, $txId, null, null, 2019))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts null for tax_year_override', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'year-null');
    $txId = taxTestTransaction($db, $userId);

    // Should not throw
    app(TagTransaction::class)->execute($userId, $txId, null, null, null);

    $tag = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect($tag)->not->toBeNull()
        ->and($tag->tax_year_override)->toBeNull();
});

it('dispatches TransactionTagged after a successful tag', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    Event::fake([TransactionTagged::class]);

    $userId = taxTestUser($db, 'event-dispatch');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId, 'Event Cat');

    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null);

    Event::assertDispatched(TransactionTagged::class, function (TransactionTagged $e) use ($userId, $txId, $catId): bool {
        return $e->userId === $userId
            && $e->transactionId === $txId
            && $e->deductionCategoryId === $catId;
    });
});

it('untagging deletes the tag row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'untag-delete');
    $txId = taxTestTransaction($db, $userId);
    $catId = taxTestCategory($db, $userId, 'Untag Cat');

    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null);
    app(UntagTransaction::class)->execute($userId, $txId);

    $count = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->count();

    expect($count)->toBe(0);
});

it('untagging a non-existent tag is a silent no-op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = taxTestUser($db, 'untag-noop');
    $txId = taxTestTransaction($db, $userId);

    // No tag exists — should not throw
    app(UntagTransaction::class)->execute($userId, $txId);

    expect(true)->toBeTrue(); // reached here = no exception
});

it('untagging a cross-user tag is a silent no-op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = taxTestUser($db, 'untag-xuser-owner');
    $partner = taxTestUser($db, 'untag-xuser-partner');
    $txId = taxTestTransaction($db, $owner);
    $catId = taxTestCategory($db, $owner, 'XUser Cat');

    // Tag as owner
    app(TagTransaction::class)->execute($owner, $txId, $catId, null, null);

    // Untag as partner — should be silent no-op
    app(UntagTransaction::class)->execute($partner, $txId);

    // Owner's tag still exists
    $count = $db->connection()
        ->table('tax_transaction_tags')
        ->where('user_id', $owner)
        ->where('transaction_id', $txId)
        ->count();

    expect($count)->toBe(1);
});
