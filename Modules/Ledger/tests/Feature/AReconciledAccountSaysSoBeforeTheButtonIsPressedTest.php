<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Enums\ClearedStatus;

// A card statement imported, reconciled, and then met again on a reload. The
// screen was byte-identical to the one that had never been reconciled: the pill
// still read matched, and Complete was the enabled primary action for a write
// that could only answer "nothing to lock" after the press.

function reconciledAccountCompleteButton(string $html): string
{
    $found = PatternScan::first('/<button\b[^>]*wire:click="confirmReconcile"[^>]*>/', $html);

    return $found[0] ?? '';
}

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'reconciled-state-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'iPhone ICS Card',
        'slug' => 'reconciled-state-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000042',
        'default_currency' => 'EUR',
    ]);

    $this->run = $this->makeImportRun($this->user);
    DB::table('import_runs')->where('id', $this->run->id)->update(['status' => 'confirmed']);

    DB::table('statement_summaries')->insert([
        'user_id' => $this->user->id,
        'import_run_id' => $this->run->id,
        'account_id' => $this->account->id,
        'iban_owner' => 'NL57ASNB0000000042',
        'period_start' => '2026-04-15',
        'period_end' => '2026-05-14',
        'closing_balance_minor' => -84732,
        'closing_balance_currency' => 'EUR',
        'closing_balance_date' => '2026-05-14',
        'entry_count' => 1,
        'created_at' => CarbonImmutable::parse('2026-05-15 09:00:00'),
        'updated_at' => CarbonImmutable::parse('2026-05-15 09:00:00'),
    ]);

    $this->earlierRow = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -80000,
        'posted_at' => '2026-05-10',
    ]);
    $this->statementRow = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -4732,
        'posted_at' => '2026-05-12',
    ]);
});

function reconcileTheFixtureAccount(): void
{
    Livewire::test(ReconcilePage::class, ['accountId' => test()->account->id])
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertSet('error', '');
}

it('shows on load that the account is already reconciled through a date', function (): void {
    reconcileTheFixtureAccount();

    expect(DB::table('transactions')->where('status', ClearedStatus::Reconciled->value)->count())->toBe(2);

    $html = Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id])->html();

    expect(str_contains($html, Fmt::shortDate('2026-05-12')))->toBeTrue(implode("\n", [
        'The account holds a reconciled row and the screen never says so.',
        'Finished work and unstarted work render the same bytes.',
    ]));
});

it('does not offer Complete as an available action when there is nothing left to lock', function (): void {
    reconcileTheFixtureAccount();

    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id]);

    $button = reconciledAccountCompleteButton($page->html());

    expect($button)->not->toBe('', 'The Complete button is not in the rendered page at all.');
    expect(str_contains($button, 'disabled'))->toBeTrue(implode("\n", [
        'Complete reconcile is offered as the enabled primary action.',
        'Pressing it can only answer "Nothing to lock for this statement date."',
        'Button as rendered: '.$button,
    ]));
    expect($page->viewData('lockableCount'))->toBe(0);
});

it('says why Complete is unavailable rather than sitting greyed out', function (): void {
    reconcileTheFixtureAccount();

    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id]);
    $html = $page->html();

    $button = reconciledAccountCompleteButton($html);

    $describedBy = PatternScan::first('/aria-describedby="([^"]+)"/', $button);

    expect($describedBy[1] ?? '')->not->toBe('', 'The disabled Complete button names no reason.');
    expect(str_contains($html, 'id="'.$describedBy[1].'"'))
        ->toBeTrue('The reason the button names is not on the page.');
    expect(str_contains($html, e(Lang::get('ledger::reconcile.complete_unavailable'))))
        ->toBeTrue('The greyed-out button carries no sentence saying why.');
});

// The half a careless fix breaks: a later statement on the same account must
// still complete. Nothing about "already reconciled" may become permanent.
it('still completes when a later statement brings rows the last reconcile did not cover', function (): void {
    reconcileTheFixtureAccount();

    $later = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -1268,
        'posted_at' => '2026-06-09',
    ]);

    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-14')
        ->set('statementBalance', '-860,00');

    expect($page->viewData('isMatched'))->toBeTrue();
    expect($page->viewData('lockableCount'))->toBe(1);
    expect(str_contains(reconciledAccountCompleteButton($page->html()), 'disabled'))
        ->toBeFalse('A later statement brought a lockable row and Complete is still off.');

    $page->call('confirmReconcile')->assertSet('error', '');

    expect(DB::table('transactions')->where('id', $later->id)->value('status'))
        ->toBe(ClearedStatus::Reconciled->value);
});

// The other repeat shape, and the one a naive "has it been reconciled?" gate
// gets wrong: one row unlocked from the detail page while the rest stay locked.
// The account is still reconciled through a date AND has something to lock.
it('still completes when one row is unlocked again under the same statement date', function (): void {
    reconcileTheFixtureAccount();

    DB::table('transactions')
        ->where('id', $this->statementRow->id)
        ->update(['status' => ClearedStatus::Cleared->value]);

    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id]);
    $html = $page->html();

    expect($page->viewData('reconciledThrough'))->not->toBeNull();
    expect($page->viewData('isMatched'))->toBeTrue();
    expect($page->viewData('lockableCount'))->toBe(1);
    expect(str_contains($html, Fmt::shortDate('2026-05-10')))
        ->toBeTrue('The rows still locked stopped being reported once one was let go.');
    expect(str_contains(reconciledAccountCompleteButton($html), 'disabled'))
        ->toBeFalse('One row is cleared again and Complete refuses to lock it.');

    $page->call('confirmReconcile');

    expect(DB::table('transactions')->where('id', $this->statementRow->id)->value('status'))
        ->toBe(ClearedStatus::Reconciled->value);
});
