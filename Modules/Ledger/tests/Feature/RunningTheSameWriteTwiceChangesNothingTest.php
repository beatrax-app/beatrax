<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

uses(RefreshDatabase::class);

// "Re-running is a genuine no-op. Every write path reads before it writes, so a
// second run over unchanged data reports zero changes rather than rewriting
// identical values." Two write paths did not hold to it, and both are reached
// from a button the reader presses more than once.

function idemUser(): User
{
    return User::query()->create([
        'username' => 'idem-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function idemTransaction(User $user): Transaction
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN idem',
        'slug' => 'idem-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/idem.xml',
        'sha256' => hash('sha256', 'idem-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Netflix International BV',
        'counterparty_normalized' => 'netflix international bv',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('idem-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('appends a note line once however many times the rule is re-applied', function (): void {
    $user = idemUser();
    $this->actingAs($user);
    $tx = idemTransaction($user);

    $note = app(SetTransactionNote::class);

    expect($note($tx->id, 'Streaming subscription', NoteMode::Append->value, $user))->toBe(1);
    expect($note($tx->id, 'Streaming subscription', NoteMode::Append->value, $user))->toBe(0);
    expect($note($tx->id, 'Streaming subscription', NoteMode::Append->value, $user))->toBe(0);

    expect($tx->fresh()->note)->toBe('Streaming subscription');

    // A genuinely new line still appends.
    expect($note($tx->id, 'Cancelled in August', NoteMode::Append->value, $user))->toBe(1);
    expect($tx->fresh()->note)->toBe("Streaming subscription\nCancelled in August");
});

it('reports no change when the category picked is the one already on the row', function (): void {
    $user = idemUser();
    $this->actingAs($user);
    $tx = idemTransaction($user);

    $category = Category::query()->create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'idem-subs-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $update = app(UpdateTransactionCategory::class);

    // Every side effect downstream is gated on this count: the merchant-memory
    // occurrence tally the ranking sorts on, the op every device replays, and
    // the manual provenance stamp that locks the field out of future rules.
    expect($update($tx->id, $category->id, $user))->toBe(1);
    expect($update($tx->id, $category->id, $user))->toBe(0);
    expect($update($tx->id, $category->id, $user))->toBe(0);

    expect($update($tx->id, null, $user))->toBe(1);
    expect($update($tx->id, null, $user))->toBe(0);
});

it('reaps the plaintext search document when the reader deletes the transaction', function (): void {
    $user = idemUser();
    $this->actingAs($user);
    $tx = idemTransaction($user);

    $db = app(DatabaseManager::class);
    app(SearchIndexWriterContract::class)->upsertForTransaction($tx->id, $user->id);

    expect($db->connection()->table('transaction_search_docs')->where('transaction_id', $tx->id)->exists())->toBeTrue();

    Livewire\Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('deleteTransaction');

    expect($db->connection()->table('transactions')->where('id', $tx->id)->exists())->toBeFalse();
    expect($db->connection()->table('transaction_search_docs')->where('transaction_id', $tx->id)->exists())->toBeFalse();
});
