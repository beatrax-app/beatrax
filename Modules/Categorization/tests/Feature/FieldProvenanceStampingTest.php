<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Tax\Public\Actions\TagTransaction;

/*
 * Plan 13.4-04 Task 3 — proves every MANUAL write path durably stamps its
 * logical field 'manual' in `transactions.field_provenance` (D-04, Req 4),
 * and that TagTransaction's engine-facing $provenanceSource param carries
 * 'rule' when the (future) rule engine invokes it. Also proves stamping
 * one field never erases another already-present entry (the
 * FieldProvenanceWriter merge contract).
 */

function fpsUser(string $suffix): User
{
    return User::create([
        'username' => 'fps-'.$suffix,
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
}

function fpsAccount(User $user): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function fpsImportRun(User $user): ImportRun
{
    return ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fps.csv',
        'sha256' => hash('sha256', 'fps-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

/** @return array<string,mixed> */
function fpsTransactionOverrides(int $accountId, int $importRunId): array
{
    return [
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test vendor',
        'counterparty_name' => 'Test Vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'fps-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
    ];
}

/** @return array<string,string> */
function fpsProvenance(int $transactionId): array
{
    $raw = DB::table('transactions')->where('id', $transactionId)->value('field_provenance');
    if (! is_string($raw) || $raw === '') {
        return [];
    }

    /** @var array<string,string> $decoded */
    $decoded = json_decode($raw, associative: true);

    return $decoded;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('saveNote — stamps field_provenance.note = manual', function (): void {
    $user = fpsUser('note');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    Event::fake([TransactionMutated::class]);

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('note', 'Manual note')
        ->call('saveNote');

    expect(fpsProvenance($tx->id))->toBe(['note' => 'manual']);
});

it('reassignCounterparty — stamps field_provenance.counterparty_id = manual', function (): void {
    $user = fpsUser('cp');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    $cp = Counterparty::create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'test-vendor-'.bin2hex(random_bytes(4)),
        'display_name' => 'Test Vendor',
    ]);

    Event::fake([TransactionMutated::class]);

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('reassignCounterparty', $cp->id);

    expect(fpsProvenance($tx->id))->toBe(['counterparty_id' => 'manual']);
});

it('AssignCategory (manual category reclassify) — stamps field_provenance.category_id = manual', function (): void {
    $user = fpsUser('cat');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    /** @var AssignsCategory $assign */
    $assign = app(AssignsCategory::class);
    $affected = $assign($tx->id, $category->id, $user);

    expect($affected)->toBe(1);
    expect(fpsProvenance($tx->id))->toBe(['category_id' => 'manual']);
});

it('binds AssignsCategory to AssignCategory so the manual stamp above exercises the real production wiring', function (): void {
    expect(app(AssignsCategory::class))->toBeInstanceOf(AssignCategory::class);
});

it('TagTransaction::execute default — stamps field_provenance.tax_tag = manual', function (): void {
    $user = fpsUser('tag');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    /** @var TagTransaction $tag */
    $tag = app(TagTransaction::class);
    $tag->execute($user->id, $tx->id, null, null, null);

    expect(fpsProvenance($tx->id))->toBe(['tax_tag' => 'manual']);
});

it('TagTransaction::execute(..., "rule") — stamps field_provenance.tax_tag = rule (the engine seam)', function (): void {
    $user = fpsUser('tagrule');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    /** @var TagTransaction $tag */
    $tag = app(TagTransaction::class);
    $tag->execute($user->id, $tx->id, null, null, null, null, 'rule');

    expect(fpsProvenance($tx->id))->toBe(['tax_tag' => 'rule']);
});

it('stamping one field does not erase another already-present field_provenance entry', function (): void {
    $user = fpsUser('merge');
    $account = fpsAccount($user);
    $run = fpsImportRun($user);
    $tx = Transaction::create(array_merge(fpsTransactionOverrides($account->id, $run->id), ['user_id' => $user->id]));

    /** @var FieldProvenanceWriter $writer */
    $writer = app(FieldProvenanceWriter::class);
    $writer->stamp($user->id, $tx->id, ['category_id' => 'manual']);

    Event::fake([TransactionMutated::class]);

    Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('note', 'Manual note')
        ->call('saveNote');

    expect(fpsProvenance($tx->id))->toBe([
        'category_id' => 'manual',
        'note' => 'manual',
    ]);
});
