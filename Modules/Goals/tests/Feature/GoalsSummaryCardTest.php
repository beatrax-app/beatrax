<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
});

it('renders the summary card and sorts goals without a projection last', function (): void {
    // Two goals exercise the null-last comparator: a linked goal that can
    // carry a projection and an unlinked goal whose projection is always null
    // and must therefore sort behind it.
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Linked goal',
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => null,
        'name' => 'Unlinked goal',
        'target_minor' => 50000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);

    Livewire::test(GoalsSummaryCard::class)
        ->assertOk()
        ->assertSee('Linked goal')
        ->assertSee('Unlinked goal');
});
