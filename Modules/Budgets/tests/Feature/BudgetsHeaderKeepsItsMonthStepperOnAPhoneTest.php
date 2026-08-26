<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;

// The header held one nowrap row at every width, with the stepper shrink-0 so
// its glyphs keep their tap targets. English's "August 2026" fitted; nothing
// longer did. Measured on a 375pt iPhone 12 mini — right edge of the stepper:
// nl 379, pt 388, es 406, hu 449, el 475. In Greek and Hungarian the next-month
// button was entirely off the screen, so those readers could not leave August.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'budgets-header-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('stacks the title and the month stepper until there is room for both', function (): void {
    $html = Livewire::test(BudgetsPage::class)->html();

    expect($html)->toContain('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between')
        ->and($html)->not->toContain('<header class="mb-6 flex items-start justify-between gap-4">');
});

// shrink-0 keeps the two glyphs' 44px reach, and on its own it is not enough:
// at AX5 the row squeezed "August 2026" to 180px and broke it as "Augus / t /
// 2026" while the buttons shrank to 68px. flex-wrap beside it gives the label
// its own row — 299px on one line, buttons back to 133px, same device.
it('leaves the stepper unshrinkable, and lets it take a second row', function (): void {
    $html = Livewire::test(BudgetsPage::class)->html();

    preg_match_all('/class="([^"]*)"/', $html, $matches);

    $stepper = array_values(array_filter(
        $matches[1],
        static fn (string $classes): bool => str_contains($classes, 'items-center') && str_contains($classes, 'gap-1'),
    ));

    expect($stepper)->not->toBe([], 'The month stepper row is gone.')
        ->and($stepper[0])->toContain('shrink-0')
        ->and($stepper[0])->toContain('flex-wrap');
});
