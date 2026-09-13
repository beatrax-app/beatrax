<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// move() writes a debit and a credit in one transaction, so a refusal has to
// land before either of them. The grid never sends these — its picker drops the
// source envelope and parseAmount() answers null below one minor unit — which is
// exactly why the writer's own guards had never been run.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'moverefusal-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(2)->startOfMonth(),
    ]);

    $this->from = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'moverefusal-from-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->to = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'moverefusal-to-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    $this->writer = app(EnvelopeWriter::class);
});

it('refuses a move into the envelope it came out of', function (): void {
    expect(fn (): int => $this->writer->move($this->user, $this->from->id, $this->from->id, $this->period->start, 1000))
        ->toThrow(InvalidArgumentException::class, Lang::get('budgets::messages.errors.same_envelope'));

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses a move of nothing and a move of less than nothing', function (): void {
    foreach ([0, -500] as $minor) {
        expect(fn (): int => $this->writer->move($this->user, $this->from->id, $this->to->id, $this->period->start, $minor))
            ->toThrow(InvalidArgumentException::class, Lang::get('budgets::messages.errors.non_positive_amount'));
    }

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->count())->toBe(0);
});

// The column is signed and the fold adds it up, so a negative assignment would
// be money the grid subtracts from a total nothing else knows it lost.
it('refuses an assignment below zero', function (): void {
    expect(fn () => $this->writer->setAssigned($this->user, $this->from->id, $this->period->start, -1))
        ->toThrow(InvalidArgumentException::class, Lang::get('budgets::messages.errors.assigned_negative'));

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});
