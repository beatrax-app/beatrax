<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Category;

// Two envelopes at one path is the grid's worst case: the row carries a money
// input and the move-money sheet carries a destination select, and neither says
// which of the two identical labels holds the reader's money. The sibling case
// (one leaf under a group, one without) is already covered next door; this is
// the case the group cannot answer.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envpath-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(3));

    $this->seeded = Category::create([
        'user_id' => null,
        'name' => 'Household',
        'slug' => 'envpath-household-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
        'name_is_default' => true,
    ]);

    $this->own = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Household',
        'slug' => 'envpath-household-own-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);
});

/**
 * @return list<string>
 */
function envelopePathAccessibleNames(string $html, string $prefix): array
{
    $matches = PatternScan::all('/aria-label="'.preg_quote($prefix, '/').' ([^"]+)"/', $html);

    return $matches[1];
}

it('gives the two envelopes different accessible names on their money inputs', function (): void {
    $html = Livewire::test(BudgetsPage::class)->assertOk()->html();

    $rows = preg_match_all('/wire:key="envelope-row-(\d+)"/', $html, $keys) === false ? [] : $keys[1];
    $labels = envelopePathAccessibleNames($html, 'Assigned for');

    expect(array_unique($rows))->toHaveCount(2)
        ->and(array_unique($labels))->toHaveCount(2);
});

it('tells the two apart in the move-money destination list', function (): void {
    $html = Livewire::test(BudgetsPage::class)->call('openMove', $this->seeded->id)->html();

    $matches = PatternScan::sets('/<option value="(\d+)">([^<]+)<\/option>/', $html);

    $labels = [];
    foreach ($matches as $match) {
        $labels[(int) $match[1]] = trim($match[2]);
    }

    $household = array_filter($labels, static fn (string $label): bool => str_contains($label, 'Household'));

    expect($household)->not->toBeEmpty()
        ->and(array_count_values(array_values($household)))->each->toBe(1);
});

it('leaves the envelope the reader always had under its own bare name', function (): void {
    $html = Livewire::test(BudgetsPage::class)->assertOk()->html();

    $assignedLabelFor = static function (int $categoryId) use ($html): string {
        $rowStart = strpos($html, 'wire:key="envelope-row-'.$categoryId.'"');
        expect($rowStart)->not->toBeFalse();

        $found = PatternScan::first('/aria-label="Assigned for ([^"]+)"/', substr($html, (int) $rowStart));
        expect($found)->not->toBeEmpty();

        return $found[1];
    };

    expect($assignedLabelFor($this->seeded->id))->toBe('Household')
        ->and($assignedLabelFor($this->own->id))->not->toBe('Household');
});
