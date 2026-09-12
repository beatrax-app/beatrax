<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Shell\Internal\Http\Livewire\Dashboard;

// ThisPeriodAtAGlanceQuery has counted the uncategorised rows since the
// dashboard was first built -- with a partial index of its own to make the
// count cheap -- and no surface ever read it. C1-R4 puts the triage counts
// above the period figures and B2-R8 requires the count to reach the reader;
// the only place it reached them was /uncategorized, which they have to go to.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00'));
    DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function waitingReader(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'waiting-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function waitingRow(User $user, string $postedAt, int $minor, ?int $categoryId): void
{
    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'waiting-'.$user->id],
        ['name' => 'ASN', 'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))), 'default_currency' => 'EUR'],
    );

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/waiting.xml',
        'sha256' => hash('sha256', 'waiting-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'category_id' => $categoryId,
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('waiting-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function waitingCategory(User $user): int
{
    /** @var Category $category */
    $category = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'waiting-groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    return (int) $category->id;
}

it('says how many transactions are waiting for a category, and where to go', function (): void {
    $user = waitingReader();
    test()->actingAs($user);
    waitingRow($user, '2026-08-03', -8000, waitingCategory($user));
    waitingRow($user, '2026-08-04', -5000, null);
    waitingRow($user, '2026-08-05', -2500, null);
    waitingRow($user, '2026-08-06', -1000, null);

    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->toContain(Lang::choice('core::dashboard.uncategorized_count', 3, ['count' => 3]))
        ->and($html)->toContain(Destination::Categorization->url());
});

// The count is above the period figures, not below them: C1-R4 is about the
// order a reader meets them in, which a substring position can measure.
it('puts the count above the period figures it makes short', function (): void {
    $user = waitingReader();
    test()->actingAs($user);
    waitingRow($user, '2026-08-04', -5000, null);

    $html = Livewire::test(Dashboard::class)->html();

    $count = mb_strpos($html, Lang::choice('core::dashboard.uncategorized_count', 1, ['count' => 1]));
    $totals = mb_strpos($html, Lang::get('core::dashboard.totals_aria'));

    expect($count)->not->toBeFalse('the dashboard never said how many rows have no category');
    expect($totals)->not->toBeFalse('the dashboard drew no period totals to compare against');
    expect((int) $count)->toBeLessThan((int) $totals);
});

it('stays quiet once every row has a category', function (): void {
    $user = waitingReader();
    test()->actingAs($user);
    waitingRow($user, '2026-08-03', -8000, waitingCategory($user));

    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->not->toContain(Lang::choice('core::dashboard.uncategorized_count', 1, ['count' => 1]));
});
