<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Core\Models\User;

// Measured on a 375pt iPhone: the header held its one row at every width, so
// the title column shrank to 141px and "Re-apply rules to history" wrapped into
// a four-line, 87px-wide column of single words beside it.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rules-header-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('stacks the title and the actions until there is room for both', function (): void {
    $html = Livewire::test(RulesPage::class)->html();

    expect($html)->toContain('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between')
        ->and($html)->not->toContain('<div class="flex items-start justify-between gap-4">');
});

it('keeps the two header actions on one line once they stack', function (): void {
    $html = Livewire::test(RulesPage::class)->html();

    expect($html)->toContain('flex flex-wrap items-center gap-2');
});
