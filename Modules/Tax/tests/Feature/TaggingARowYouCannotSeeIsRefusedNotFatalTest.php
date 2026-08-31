<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;

uses(RefreshDatabase::class);

// Three components mount this trait, all of them another module's Internal.
// Hosting it here keeps the test about the trait's own guard rather than about
// whichever consumer it was found on, and spares the boundary pin a crossing.
final class TaxTaggingRefusalHost extends Component
{
    use HandlesTaxTagging;

    public function render(): string
    {
        return '<div></div>';
    }
}

function tagRefusedUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tagRefusedTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Refused '.$suffix,
        'slug' => 'refused-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00REFU'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/refused-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'refused-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'refused-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 00:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -3000,
        'currency' => 'EUR',
        'settled_amount_minor' => -3000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Refused Vendor BV',
        'counterparty_normalized' => 'refused-vendor',
        'normalization_version' => 1,
        'description' => 'Refused row '.$suffix,
        'type' => TransactionType::Expense->value,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// The id reaches tagTransaction() straight off the wire, so it names whatever
// the sender put there. saveTaxCategory() two methods down already answers a
// row it cannot find with a toast; this pins the same answer here.
it('answers a toast rather than a 404 when the tag target is not the readers row', function (): void {
    $db = $this->app->make(DatabaseManager::class);
    $reader = tagRefusedUser('tag-refused-reader');
    $neighbour = tagRefusedUser('tag-refused-neighbour');
    $foreignId = tagRefusedTransaction($db, $neighbour->id);

    $this->actingAs($reader);

    Livewire::test(TaxTaggingRefusalHost::class)
        ->call('tagTransaction', $foreignId)
        ->assertOk();

    expect($db->connection()->table('tax_transaction_tags')->count())->toBe(0);
});

it('answers a toast rather than a 404 when the tag target does not exist at all', function (): void {
    $db = $this->app->make(DatabaseManager::class);
    $reader = tagRefusedUser('tag-missing-reader');

    $this->actingAs($reader);

    Livewire::test(TaxTaggingRefusalHost::class)
        ->call('tagTransaction', 987654321)
        ->assertOk();

    expect($db->connection()->table('tax_transaction_tags')->count())->toBe(0);
});
