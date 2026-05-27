<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The rule seeder resolves category_id by slug from the global
    // category tree, so the tree seeder must run first in every test.
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    $this->user = User::query()->create([
        'username' => 'rule-seeder-test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('seeds at least the fixture-defined rule count scoped to the user', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    /** @var int $expectedCount */
    $expectedCount = count(require base_path('Modules/Categorization/Database/Seeders/default-categorization-rules.php'));

    $actualCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    expect($actualCount)->toBe($expectedCount)
        ->and($expectedCount)->toBeGreaterThanOrEqual(80);
});

it('resolves every fixture row to a real category_id', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rulesWithoutCategory = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->whereNull('category_id')
        ->count();

    expect($rulesWithoutCategory)->toBe(0);
});

it('locks firstOrCreate semantics — re-running preserves hits_count and active', function (): void {
    $seeder = $this->app->make(DefaultCategorizationRuleSeeder::class);
    $seeder->run($this->user);

    $countAfterFirstRun = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    // Mutate one row to the sentinel state that updateOrCreate would
    // overwrite: hits_count=42, active=false.
    /** @var CategorizationRule $sampleRule */
    $sampleRule = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('field', 'counterparty')
        ->where('match', 'contains')
        ->where('value', 'Netflix')
        ->firstOrFail();

    $sampleRule->update([
        'hits_count' => 42,
        'active' => false,
    ]);

    // Re-run — must NOT touch the mutated row.
    $seeder->run($this->user);

    $countAfterSecondRun = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    expect($countAfterSecondRun)->toBe($countAfterFirstRun);

    $sampleRule->refresh();
    expect($sampleRule->hits_count)->toBe(42)
        ->and($sampleRule->active)->toBeFalse();
});

it('produces a per-user rule set scoped to the second user when re-run', function (): void {
    /** @var User $secondUser */
    $secondUser = User::query()->create([
        'username' => 'second-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $seeder = $this->app->make(DefaultCategorizationRuleSeeder::class);
    $seeder->run($this->user);
    $seeder->run($secondUser);

    $firstUserCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();
    $secondUserCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $secondUser->id)
        ->count();

    expect($firstUserCount)->toBeGreaterThanOrEqual(80)
        ->and($secondUserCount)->toBe($firstUserCount);
});

it('seeds the Netflix → subscriptions-streaming anchor rule', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('field', 'counterparty')
        ->where('match', 'contains')
        ->where('value', 'Netflix')
        ->firstOrFail();

    $categorySlug = Category::withoutGlobalScopes()
        ->where('id', $rule->category_id)
        ->value('slug');

    expect($categorySlug)->toBe('subscriptions-streaming');
});

it('seeds the cash-withdrawal anchor rule with the starts_with operator', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('field', 'counterparty')
        ->where('match', 'starts_with')
        ->where('value', 'KOSTEN KASOPNAME')
        ->firstOrFail();

    $categorySlug = Category::withoutGlobalScopes()
        ->where('id', $rule->category_id)
        ->value('slug');

    expect($categorySlug)->toBe('cash-withdrawal');
});

it('seeds the transfers-internal anchor rule against the description field', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('field', 'description')
        ->where('match', 'contains')
        ->where('value', 'IDEAL BETALING, DANK U')
        ->firstOrFail();

    $categorySlug = Category::withoutGlobalScopes()
        ->where('id', $rule->category_id)
        ->value('slug');

    expect($categorySlug)->toBe('transfers-internal');
});

it('runs from the beatrax:install command via the UserInstalled listener end-to-end', function (): void {
    // Drive the install command — categories AND rules must land via
    // the two listeners on UserInstalled.
    $exit = $this->app->make(ConsoleKernel::class)->call('beatrax:install', [
        '--username' => 'install-rules',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ]);

    expect($exit)->toBe(0);

    $ruleCount = CategorizationRule::withoutGlobalScopes()->count();
    expect($ruleCount)->toBeGreaterThanOrEqual(80);
});
