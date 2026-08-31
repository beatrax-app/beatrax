<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// The two overspend modes differ only in who absorbs a negative envelope, and
// the select offers both with nothing on the screen saying which is which. The
// help is held to the standard the priority help set: the claim is read out of
// the shipped sentence and then executed, so a rewrite that keeps the meaning
// keeps these tests and one that swaps the two modes fails.

/** @return list<string> every locale the app is offered in */
function budgetsHelpLocales(): array
{
    return array_map(static fn (Locale $case): string => $case->value, Locale::cases());
}

function overspendHelpRenderedIn(string $locale): string
{
    $help = require base_path('Modules/Budgets/Resources/lang/'.$locale.'/help.php');
    $labels = require base_path('Modules/Budgets/Resources/lang/'.$locale.'/messages.php');

    return str_replace(
        [':reduce', ':carry'],
        [$labels['overspend']['reduce'], $labels['overspend']['carry']],
        (string) $help['if_overspent'],
    );
}

function budgetsHelpReader(): User
{
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    return User::create([
        'username' => 'overspend-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

it('names both of the options the select actually offers, in every language', function (): void {
    $silent = [];

    foreach (budgetsHelpLocales() as $locale) {
        $labels = require base_path('Modules/Budgets/Resources/lang/'.$locale.'/messages.php');
        $rendered = overspendHelpRenderedIn($locale);

        foreach (['reduce', 'carry'] as $mode) {
            if (! str_contains($rendered, (string) $labels['overspend'][$mode])) {
                $silent[] = $locale.'/'.$mode;
            }
        }
    }

    expect($silent)->toBe([], implode("\n", [
        'These locales explain a mode in words the option beside it does not use:',
        ...$silent,
        '',
        'The sentence carries :reduce and :carry so it is always written in the',
        'words the select itself shows. A locale that paraphrases them describes',
        'two options a reader cannot match to the two on the screen.',
    ]));
});

it('translates the sentence rather than shipping the English one', function (): void {
    $english = (require base_path('Modules/Budgets/Resources/lang/en/help.php'))['if_overspent'];

    $untranslated = [];
    foreach (array_diff(budgetsHelpLocales(), ['en']) as $locale) {
        $file = base_path('Modules/Budgets/Resources/lang/'.$locale.'/help.php');
        if (! is_file($file)) {
            $untranslated[] = $locale.' (no file)';

            continue;
        }
        if (((require $file)['if_overspent'] ?? '') === $english) {
            $untranslated[] = $locale;
        }
    }

    expect($untranslated)->toBe([]);
});

// The claim: reduce-to-budget resets the envelope and takes the shortfall off
// what is left to plan with; carry-negative leaves it in the envelope and moves
// nothing else. Both halves are executed against the fold the grid reads.
it('is telling the truth about where a shortfall lands', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 12:00:00'));

    $user = budgetsHelpReader();
    $this->actingAs($user);
    DB::table('users')->where('id', $user->id)->update(['envelope_activated_at' => '2026-07-01 09:00:00']);
    $user->refresh();

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN overspend',
        'slug' => 'oh-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/overspend-help.xml',
        'sha256' => hash('sha256', 'oh-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $reduce = Category::create(['user_id' => null, 'name' => 'Reduce', 'slug' => 'oh-reduce-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);
    $carry = Category::create(['user_id' => null, 'name' => 'Carry', 'slug' => 'oh-carry-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 2]);

    $modes = [$reduce->id => OverspendMode::ReduceToBudget, $carry->id => OverspendMode::CarryNegative];
    foreach ($modes as $categoryId => $mode) {
        DB::table('envelope_settings')->insert([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'overspend_mode' => $mode->value,
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ]);
        DB::table('envelope_assignments')->insert([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'period_start' => '2026-07-01',
            'assigned_minor' => 1000,
            'currency' => 'EUR',
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ]);

        Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => '2026-07-10',
            'booked_at' => '2026-07-10 12:00:00',
            'value_date' => '2026-07-10',
            'amount_minor' => -1500,
            'currency' => 'EUR',
            'settled_amount_minor' => -1500,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Overspender '.$categoryId,
            'counterparty_normalized' => 'overspender '.$categoryId,
            'normalization_version' => 1,
            'category_id' => $categoryId,
            'source_format' => 'camt053',
            'import_run_id' => $run->id,
            'source_row_index' => $categoryId,
            'fingerprint' => hash('sha256', 'oh-'.$categoryId.'-'.bin2hex(random_bytes(6))),
            'fingerprint_version' => 1,
        ]);
    }

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => 3000,
        'currency' => 'EUR',
        'settled_amount_minor' => 3000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Payroll',
        'counterparty_normalized' => 'payroll',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 99,
        'fingerprint' => hash('sha256', 'oh-income-'.bin2hex(random_bytes(6))),
        'fingerprint_version' => 1,
    ]);

    /** @var CarryoverQuery $fold */
    $fold = $this->app->make(CarryoverQuery::class);
    $august = $fold->forUserAndPeriod($user, app(PeriodQuery::class)->current());

    expect($august['rows'][$reduce->id]->carriedInMinor)->toBe(
        0,
        'The help says the reduce-to-budget envelope starts the next period again at zero.',
    );
    expect($august['rows'][$carry->id]->carriedInMinor)->toBe(
        -500,
        'The help says the carry-negative envelope opens the next period below zero.',
    );
    // 3000 in, 2000 assigned, both envelopes 500 over. Only the
    // reduce-to-budget one may reach the pool: 500 here, 1000 if neither did,
    // 0 if both did, so the three readings cannot be confused.
    expect($august['toBudgetMinor'])->toBe(
        500,
        'The help says only the reduce-to-budget shortfall comes off what is left to plan with next period.',
    );

    CarbonImmutable::setTestNow();
});

it('draws the tip on the header of the column whose control it explains', function (): void {
    $user = budgetsHelpReader();
    $this->actingAs($user);

    Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'oh-grocery-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);

    $html = Livewire::test(BudgetsPage::class)->html();

    expect($html)->toContain('id="help-tip-budgets-overspend"')
        ->and($html)->toContain('aria-label="'.e(Lang::get('core::help.tip.about', ['subject' => Lang::get('budgets::messages.table.if_overspent')])).'"')
        ->and($html)->toContain(e(Lang::get('budgets::messages.overspend.reduce')))
        ->and($html)->toContain(e(Lang::get('budgets::messages.overspend.carry')));
});

// The grid is desktop-only markup; the ready-to-assign block is not, which is
// why the tip a phone reader can reach is the one on it.
it('draws the ready-to-assign tip outside the desktop-only grid', function (): void {
    $user = budgetsHelpReader();
    $this->actingAs($user);

    Category::create(['user_id' => null, 'name' => 'Transport', 'slug' => 'oh-transport-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);

    $html = Livewire::test(BudgetsPage::class)->html();

    expect($html)->toContain('popovertarget="help-tip-budgets-ready"')
        ->and(strpos($html, 'help-tip-budgets-ready'))
        ->toBeLessThan((int) strpos($html, 'hidden overflow-x-auto md:block'));
});
