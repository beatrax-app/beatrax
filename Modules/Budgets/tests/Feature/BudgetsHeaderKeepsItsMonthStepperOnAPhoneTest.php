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

// shrink-0 is deliberate and must survive the stack: a stepper that shrinks
// loses the 44px reach of the two glyphs, which is the defect the other way.
it('leaves the stepper itself unshrinkable once it has its own row', function (): void {
    $html = Livewire::test(BudgetsPage::class)->html();

    expect($html)->toContain('flex shrink-0 items-center gap-1');
});
