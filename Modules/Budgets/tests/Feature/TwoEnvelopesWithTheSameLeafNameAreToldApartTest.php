<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Category;

// A migrated tree and the seeded one can each hold a "Groceries", under
// different parents. The envelope grid printed the leaf, so the two rows were
// byte-identical and the only thing telling them apart was the wire:key —
// which is the assignment target for real money.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envcollide-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(3));

    $this->group = Category::create([
        'user_id' => null,
        'name' => 'Frequent',
        'slug' => 'envcollide-frequent-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->grouped = Category::create([
        'user_id' => null,
        'parent_id' => $this->group->id,
        'name' => 'Groceries',
        'slug' => 'envcollide-grouped-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $this->standalone = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'envcollide-standalone-'.$suffix,
        'kind' => 'expense',
        'display_order' => 3,
    ]);
});

/**
 * @return list<string>
 */
function envelopeAccessibleNames(string $html, string $prefix): array
{
    $matches = PatternScan::all('/aria-label="'.preg_quote($prefix, '/').' ([^"]+)"/', $html);

    return $matches[1];
}

it('gives the two same-named envelopes different accessible names on their money inputs', function (): void {
    $html = Livewire::test(BudgetsPage::class)->assertOk()->html();

    $labels = envelopeAccessibleNames($html, 'Assigned for');

    // The grid renders every envelope twice — the desktop table and the phone
    // card list are both in the markup — so one distinct label per envelope is
    // the assertion, not one occurrence.
    $rows = preg_match_all('/wire:key="envelope-row-(\d+)"/', $html, $keys) === false ? [] : $keys[1];

    expect($labels)->not->toBeEmpty()
        ->and(array_unique($rows))->toHaveCount(3)
        ->and(array_unique($labels))->toHaveCount(count(array_unique($rows)));
});

it('names the group on the envelope row of a category that has one', function (): void {
    $html = Livewire::test(BudgetsPage::class)->assertOk()->html();

    $rowStart = strpos($html, 'wire:key="envelope-row-'.$this->grouped->id.'"');
    expect($rowStart)->not->toBeFalse();

    $rowEnd = strpos($html, '</tr>', (int) $rowStart);
    $row = substr($html, (int) $rowStart, (int) $rowEnd - (int) $rowStart);

    expect($row)->toContain('Frequent');
});

it('leaves the standalone envelope row unqualified', function (): void {
    $html = Livewire::test(BudgetsPage::class)->assertOk()->html();

    $rowStart = strpos($html, 'wire:key="envelope-row-'.$this->standalone->id.'"');
    expect($rowStart)->not->toBeFalse();

    $rowEnd = strpos($html, '</tr>', (int) $rowStart);
    $row = substr($html, (int) $rowStart, (int) $rowEnd - (int) $rowStart);

    expect($row)->not->toContain('Frequent');
});

it('tells the two apart in the move-money destination list', function (): void {
    $html = Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->group->id)
        ->html();

    $matches = PatternScan::all('/<option value="\d+">([^<]+)<\/option>/', $html);

    // The desktop modal and the phone sheet render the same destination list,
    // so every label appears twice; what matters is how many distinct ones.
    $groceries = array_values(array_unique(array_filter(
        array_map(trim(...), $matches[1]),
        static fn (string $label): bool => str_contains($label, 'Groceries'),
    )));

    expect($groceries)->toHaveCount(2);
});
