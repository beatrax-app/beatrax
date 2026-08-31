<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;

// "Archived goals (1)" put a count beside a bare plural noun. English reads
// wrong at one and that is the smaller half: the line ships in 26 locales,
// several of which select a form off the final digit, and a line with no
// selector gives the translator nowhere to put their own grammar.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->archive = function (string $name): void {
        Goal::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'target_minor' => 50000,
            'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
            'status' => 'archived',
        ]);
    };
});

it('reads as one archived goal when there is one', function (): void {
    ($this->archive)('Winterbanden');

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::choice('goals::messages.archived_disclosure', 1));

    expect(Lang::choice('goals::messages.archived_disclosure', 1))->toBe('Archived goal (1)');
});

it('reads as several archived goals when there are several', function (): void {
    ($this->archive)('Winterbanden');
    ($this->archive)('Japan');
    ($this->archive)('Laptop');

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::choice('goals::messages.archived_disclosure', 3));

    expect(Lang::choice('goals::messages.archived_disclosure', 3))->toBe('Archived goals (3)');
});

it('never renders the selector itself to the reader', function (): void {
    ($this->archive)('Winterbanden');

    expect((string) Livewire::test(GoalsPage::class)->html())
        ->not->toContain('Archived goal (1)|Archived goals (1)');
});
