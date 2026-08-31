<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// prevPeriod(), nextPeriod() and render() clamp periodStartStr to
// [genesis, now + horizon]; setAssigned(), moveMoney() and copyLastMonth() hand
// it straight to PeriodQuery::resolveAnchor(), which accepts any well-formed
// Y-m-d. Unlocked, a forged 2099 anchor wrote a real envelope_assignments row
// in a month the fold bounds out of every view and navigation cannot reach —
// money assigned to a period with no screen to show it on.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'budget-period-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);

    $this->categoryId = (int) Category::create([
        'user_id' => null,
        'name' => 'Car maintenance',
        'slug' => 'period-lock-car-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ])->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

function budgetsPageSnapshot(): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/budgets')->assertOk()->getContent(),
        'budgets.budgets-page',
    );
}

it('refuses a payload that anchors the assignment to an unreachable month', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        budgetsPageSnapshot(),
        ['periodStartStr' => '2099-06-15', 'assignedInputs.'.$this->categoryId => '250,00'],
        [['path' => '', 'method' => 'setAssigned', 'params' => [$this->categoryId]]],
    )->assertForbidden();

    expect($this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('still assigns into the period the page is showing', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        budgetsPageSnapshot(),
        ['assignedInputs.'.$this->categoryId => '250,00'],
        [['path' => '', 'method' => 'setAssigned', 'params' => [$this->categoryId]]],
    )->assertOk();

    $rows = $this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->assigned_minor)->toBe(25000)
        ->and((string) $rows[0]->period_start)->not->toStartWith('2099');
});
