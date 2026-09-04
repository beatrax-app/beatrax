<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Shell\Internal\Http\Livewire\Dashboard;

// Groceries -EUR80.00, Electronics -EUR50.00 and an Electronics refund of
// +EUR400.00 in one period made the top-spending card print "No categorized
// expenses yet." over the three categorised rows listed directly beneath it.
// With a EUR125.00 refund instead, the denominator came to EUR5.00 and
// Groceries drew a full bar announced as 100 for EUR80.00 of EUR130.00, while
// Electronics was ranked as spending at -EUR75.00 under aria-valuenow="2".
// @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-one-directional-figure-ranked-on-a-signed-sum

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00'));
    DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function refundRankingReader(): User
{
    return User::query()->create([
        'username' => 'refund-ranking-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function refundRankingCategory(User $user, string $name): int
{
    /** @var Category $category */
    $category = Category::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'rr-'.mb_strtolower($name).'-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    return (int) $category->id;
}

function refundRankingRow(User $user, string $postedAt, string $counterparty, int $minor, int $categoryId, string $type): void
{
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'rr-'.$user->id],
        ['name' => 'ASN', 'kind' => 'bank', 'iban' => 'NL00ASNB'.mb_strtoupper(bin2hex(random_bytes(4))), 'default_currency' => 'EUR'],
    );

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rr.xml',
        'sha256' => hash('sha256', 'rr-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'category_id' => $categoryId,
        'counterparty_name' => $counterparty,
        'counterparty_normalized' => mb_strtolower($counterparty),
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rr-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// Everything between the "Top spending" heading and the card that follows it,
// so an assertion about the ranking cannot pass on the recent-transactions
// list printing the very figures the ranking left out.
function refundRankingCard(string $html): string
{
    $start = mb_strpos($html, Lang::get('core::dashboard.top_spending'));
    expect($start)->not->toBeFalse('the dashboard drew no top-spending card at all');

    $card = mb_substr($html, (int) $start);
    $end = mb_strpos($card, Lang::get('core::dashboard.recent_transactions'));

    return $end === false ? $card : mb_substr($card, 0, (int) $end);
}

/**
 * @return list<array{now: float, max: float, width: float}>
 */
function refundRankingBars(string $html): array
{
    $matches = PatternScan::sets(
        '/role="progressbar"\s+aria-valuenow="([^"]*)"\s+aria-valuemin="0"\s+aria-valuemax="([^"]*)"(.*?)style="width:\s*([0-9.]+)%/s',
        $html,
    );

    return array_map(
        static fn (array $bar): array => ['now' => (float) $bar[1], 'max' => (float) $bar[2], 'width' => (float) $bar[4]],
        $matches,
    );
}

it('ranks no category whose refunds outran its spending, and says what it left out', function (): void {
    $user = refundRankingReader();
    $this->actingAs($user);
    $groceries = refundRankingCategory($user, 'Groceries');
    $electronics = refundRankingCategory($user, 'Electronics');

    refundRankingRow($user, '2026-08-03', 'Albert Heijn', -8000, $groceries, 'expense');
    refundRankingRow($user, '2026-08-04', 'Coolblue', -5000, $electronics, 'expense');
    refundRankingRow($user, '2026-08-05', 'Coolblue', 40000, $electronics, 'refund');

    $card = refundRankingCard(Livewire::test(Dashboard::class)->html());

    expect($card)->toContain('Groceries');
    expect($card)->not->toContain(Lang::get('core::dashboard.no_expenses'));
    expect($card)->not->toContain('Electronics');
    expect($card)->toContain(Lang::get('core::dashboard.top_spending_refunded', ['amount' => '€350.00']));
});

it('never prints a negative figure in the ranking', function (): void {
    $user = refundRankingReader();
    $this->actingAs($user);
    $groceries = refundRankingCategory($user, 'Groceries');
    $electronics = refundRankingCategory($user, 'Electronics');

    refundRankingRow($user, '2026-08-03', 'Albert Heijn', -8000, $groceries, 'expense');
    refundRankingRow($user, '2026-08-04', 'Coolblue', -5000, $electronics, 'expense');
    refundRankingRow($user, '2026-08-05', 'Coolblue', 12500, $electronics, 'refund');

    $card = refundRankingCard(Livewire::test(Dashboard::class)->html());

    // The minus sign Money draws, not an ASCII hyphen: a class name full of
    // dashes would otherwise answer for the figure.
    expect($card)->not->toContain('-€');
    expect($card)->not->toContain('−€');
    expect($card)->toContain('€80.00');
});

it('announces on every bar the width it drew', function (): void {
    $user = refundRankingReader();
    $this->actingAs($user);
    $groceries = refundRankingCategory($user, 'Groceries');
    $electronics = refundRankingCategory($user, 'Electronics');

    refundRankingRow($user, '2026-08-03', 'Albert Heijn', -8000, $groceries, 'expense');
    refundRankingRow($user, '2026-08-04', 'Coolblue', -5000, $electronics, 'expense');
    refundRankingRow($user, '2026-08-05', 'Coolblue', 12500, $electronics, 'refund');

    $bars = refundRankingBars(Livewire::test(Dashboard::class)->html());

    expect($bars)->not->toBe([]);

    foreach ($bars as $bar) {
        expect($bar['now'])->toBeGreaterThanOrEqual(0.0);
        expect($bar['now'])->toBeLessThanOrEqual($bar['max']);
        expect(abs($bar['width'] - ($bar['now'] / $bar['max']) * 100))->toBeLessThan(0.011);
    }
});

it('clamps what it announces as well as what it draws, whatever it is handed', function (): void {
    /** @var ViewFactory $views */
    $views = $this->app->make(ViewFactory::class);

    foreach ([-20 => 0.0, 1600 => 100.0, 42 => 42.0] as $value => $expected) {
        $bars = refundRankingBars(
            $views->make('core::components.progress-bar', ['value' => $value, 'label' => 'probe'])->render()
        );

        expect($bars)->toHaveCount(1);
        expect($bars[0]['now'])->toBe($expected);
        expect($bars[0]['width'])->toBe($expected);
    }
});

it('still says nothing is there when nothing is', function (): void {
    $user = refundRankingReader();
    $this->actingAs($user);
    refundRankingRow($user, '2026-08-03', 'Albert Heijn', -8000, refundRankingCategory($user, 'Groceries'), 'expense');

    $card = refundRankingCard(Livewire::test(Dashboard::class)->set('periodStartStr', '2026-07-01')->html());

    expect($card)->toContain(Lang::get('core::dashboard.no_expenses'));
    expect($card)->not->toContain(Lang::get('core::dashboard.top_spending_refunded', ['amount' => '€0.00']));
});

it('leaves an unrefunded period ranked largest first, with the shares it always had', function (): void {
    $user = refundRankingReader();
    $this->actingAs($user);

    refundRankingRow($user, '2026-08-03', 'Albert Heijn', -7500, refundRankingCategory($user, 'Groceries'), 'expense');
    refundRankingRow($user, '2026-08-04', 'NS', -2500, refundRankingCategory($user, 'Transport'), 'expense');

    $card = refundRankingCard(Livewire::test(Dashboard::class)->html());

    expect(mb_strpos($card, 'Groceries'))->toBeLessThan((int) mb_strpos($card, 'Transport'));
    expect($card)->toContain('€75.00');
    expect($card)->toContain('€25.00');

    $bars = refundRankingBars($card);
    expect(array_column($bars, 'now'))->toBe([75.0, 25.0]);
});
