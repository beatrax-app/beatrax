<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;

uses(RefreshDatabase::class);

// field_provenance['category_id'] === 'manual' is the ONE thing that stops the
// rule engine's re-apply overwriting a category (RuleApplier::applyAtReapply
// skips exactly that value). Reclassifying stamps it. Picking the surviving
// category in the unsplit dialog is the same decision by the same reader
// through a different button, and wrote the column without the stamp — so a
// later "re-apply to history" silently took the choice back.

function usdUser(): User
{
    return User::create([
        'username' => 'unsplit-prov-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @return array{0: User, 1: Transaction, 2: Category, 3: Category}
 */
function unsplitProvFixture(): array
{
    $user = usdUser();

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL'.bin2hex(random_bytes(7)),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'g-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);
    $household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'h-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 2]);

    $today = CarbonImmutable::now()->toDateString();
    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'category_id' => $groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => random_int(1, 999999),
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, '0'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
    ]);

    return [$user, $tx, $groceries, $household];
}

it('stamps the category a reclassify chose as the reader own', function (): void {
    [$user, $tx, , $household] = unsplitProvFixture();

    app(AssignsCategory::class)($tx->id, $household->id, $user);

    expect(app(FieldProvenanceWriter::class)->provenanceFor($user->id, $tx->id))
        ->toBe(['category_id' => 'manual']);
});

it('stamps the surviving category an unsplit chose as the reader own', function (): void {
    [$user, $tx, $groceries, $household] = unsplitProvFixture();

    app(SavesTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('unsplit')
        ->call('selectUnsplitSurvivor', 1)
        ->call('confirmUnsplitAction');

    expect(Transaction::query()->find($tx->id)?->category_id)->toBe($household->id);

    expect(app(FieldProvenanceWriter::class)->provenanceFor($user->id, $tx->id))
        ->toBe(['category_id' => 'manual']);
});
