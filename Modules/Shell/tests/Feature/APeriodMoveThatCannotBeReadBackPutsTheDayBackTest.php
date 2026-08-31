<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// The rekey is what makes a new period-start day mean anything: every envelope
// row is keyed by a literal date, and the day saved without the rekey leaves
// the plan matching no period the carryover fold walks.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'period-move-unreadable',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'period-move-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-10 09:00:00']);
    $this->user->refresh();
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, CarbonImmutable::parse('2026-07-15'), 10000);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('leaves the budget month where it was when the rekey cannot read its rows back', function (): void {
    // The row is gone by the time the rekey reads its id back, which is the one
    // outcome the read-back sites disagreed about.
    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with(ltrim($query->sql), 'insert into "envelope_assignments"')) {
            DB::table('envelope_assignments')->delete();
        }
    });

    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 28)
        ->call('save')
        ->call('save')
        ->assertHasErrors('periodStartDay');

    expect(DB::table('users')->where('id', $this->user->id)->value('period_start_day'))->toBe(15)
        ->and(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(1);
});
