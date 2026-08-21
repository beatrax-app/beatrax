<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\CorrectionDivergenceToast;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Events\CategorizationDiverged;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'divergence',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-div',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming-div',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->music = Category::create([
        'user_id' => null,
        'name' => 'Music',
        'slug' => 'music-div',
        'kind' => 'expense',
        'display_order' => 110,
    ]);
});

function seedDivergenceRule(User $user, int $categoryId, string $value = 'SPOTIFY'): int
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    return ($create)(
        $user,
        new RuleInput(
            priority: 10,
            combinator: 'all',
            active: true,
            notes: null,
            conditions: [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => $value]],
            actions: [['type' => 'category', 'payload' => ['category_id' => $categoryId]]],
        ),
    );
}

function divergenceRuleCategoryId(int $ruleId): int
{
    $action = DB::table('rule_actions')->where('rule_id', $ruleId)->where('type', 'category')->first();
    if ($action === null) {
        return 0;
    }
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $action->payload, true);

    return isset($payload['category_id']) && is_numeric($payload['category_id']) ? (int) $payload['category_id'] : 0;
}

function seedTransactionWithProvenance(int $userId, int $accountId, int $importRunId, int $categoryId, array $provenance): int
{
    $tx = Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('c', 64),
        'fingerprint_version' => 1,
        'category_id' => $categoryId,
        'auto_category_provenance' => $provenance,
    ]);

    return (int) $tx->id;
}

function resolveAssign(): AssignsCategory
{
    /** @var AssignsCategory $action */
    $action = Container::getInstance()->make(AssignsCategory::class);

    return $action;
}

it('dispatches CategorizationDiverged when reclassifying a rule-provenance transaction to a different category', function (): void {
    Event::fake([CategorizationDiverged::class]);

    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);
    $txId = seedTransactionWithProvenance(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    $assign = resolveAssign();
    $assign($txId, $this->music->id, $this->user);

    Event::assertDispatched(
        CategorizationDiverged::class,
        fn (CategorizationDiverged $e): bool => $e->transactionId === $txId
            && $e->ruleId === $ruleId
            && $e->oldCategoryId === $this->streaming->id
            && $e->newCategoryId === $this->music->id
            && $e->userId === $this->user->id,
    );
});

it('does NOT dispatch CategorizationDiverged when the prior provenance was memory', function (): void {
    Event::fake([CategorizationDiverged::class]);

    $txId = seedTransactionWithProvenance(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'memory', 'rule_id' => null, 'memory_id' => 99, 'category_id' => $this->streaming->id],
    );

    $assign = resolveAssign();
    $assign($txId, $this->music->id, $this->user);

    Event::assertNotDispatched(CategorizationDiverged::class);
});

it('does NOT dispatch CategorizationDiverged when there is no prior provenance (manual)', function (): void {
    Event::fake([CategorizationDiverged::class]);

    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('d', 64),
        'fingerprint_version' => 1,
    ]);

    $assign = resolveAssign();
    $assign($tx->id, $this->music->id, $this->user);

    Event::assertNotDispatched(CategorizationDiverged::class);
});

it('does NOT dispatch CategorizationDiverged when the new category equals the rule category', function (): void {
    Event::fake([CategorizationDiverged::class]);

    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);
    $txId = seedTransactionWithProvenance(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    $assign = resolveAssign();
    $assign($txId, $this->streaming->id, $this->user);

    Event::assertNotDispatched(CategorizationDiverged::class);
});

it('CorrectionDivergenceToast renders Update the rule? when handleDiverged fires for the current user', function (): void {
    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);

    Livewire::test(CorrectionDivergenceToast::class)
        ->call(
            'handleDiverged',
            transactionId: 42,
            ruleId: $ruleId,
            oldCategoryId: $this->streaming->id,
            newCategoryId: $this->music->id,
            userId: $this->user->id,
        )
        ->assertSet('visible', true)
        ->assertSet('ruleId', $ruleId)
        ->assertSet('newCategoryId', $this->music->id)
        ->assertSee('Update the rule?');
});

it('CorrectionDivergenceToast guards against cross-user events (T-07-09)', function (): void {
    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);
    $foreignUserId = 9999;

    Livewire::test(CorrectionDivergenceToast::class)
        ->call(
            'handleDiverged',
            transactionId: 42,
            ruleId: $ruleId,
            oldCategoryId: $this->streaming->id,
            newCategoryId: $this->music->id,
            userId: $foreignUserId,
        )
        ->assertSet('visible', false)
        ->assertDontSee('Update the rule?');
});

it('CorrectionDivergenceToast.update() calls UpdateCategorizationRule with the new category', function (): void {
    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);

    Livewire::test(CorrectionDivergenceToast::class)
        ->call(
            'handleDiverged',
            transactionId: 42,
            ruleId: $ruleId,
            oldCategoryId: $this->streaming->id,
            newCategoryId: $this->music->id,
            userId: $this->user->id,
        )
        ->call('update')
        ->assertSet('visible', false)
        ->assertSet('flashMessage', 'Rule updated.');

    expect(divergenceRuleCategoryId($ruleId))->toBe($this->music->id);
});

it('CorrectionDivergenceToast.dismiss() hides the toast without changing the rule', function (): void {
    $ruleId = seedDivergenceRule($this->user, $this->streaming->id);

    Livewire::test(CorrectionDivergenceToast::class)
        ->call(
            'handleDiverged',
            transactionId: 42,
            ruleId: $ruleId,
            oldCategoryId: $this->streaming->id,
            newCategoryId: $this->music->id,
            userId: $this->user->id,
        )
        ->call('dismiss')
        ->assertSet('visible', false);

    expect(divergenceRuleCategoryId($ruleId))->toBe($this->streaming->id);
});

it('the toast Blade carries an 8-second auto-dismiss timer per UI-SPEC', function (): void {
    $bladePath = base_path('Modules/Categorization/Resources/views/livewire/correction-divergence-toast.blade.php');
    $contents = (string) file_get_contents($bladePath);

    expect($contents)->toContain('8000');
    expect($contents)->toContain('setTimeout');
});

it('registers the correction-divergence-toast Livewire alias', function (): void {
    // Mounting by alias goes through the same registry @livewire('alias') uses.
    Livewire::test('categorization.correction-divergence-toast')
        ->assertSet('visible', false);
});

it('CorrectionDivergenceToast has zero facade usage in the class body', function (): void {
    $path = base_path('Modules/Categorization/Internal/Http/Livewire/CorrectionDivergenceToast.php');
    $contents = (string) file_get_contents($path);
    // A PHPDoc reference to these names stays legal, so strip comments first.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

    expect($stripped)->not->toMatch('/Auth::/');
    expect($stripped)->not->toMatch('/Cache::/');
    expect($stripped)->not->toMatch('/DB::/');
    expect($stripped)->not->toMatch('/View::/');
    expect($stripped)->not->toMatch('/\bauth\(\)/');
    expect($stripped)->not->toMatch('/\bconfig\(\)/');
    expect($stripped)->not->toMatch('/\brequest\(\)/');
    expect($stripped)->not->toMatch('/\bview\(\)/');
});

it('CorrectionDivergenceToast.update() catches NotFoundHttpException for a tampered foreign ruleId and surfaces a calm flash (not a 500)', function (): void {
    // handleDiverged hydrates ruleId from the Livewire-local event, so a
    // tampered payload can carry another user's ruleId. The SFC must catch the
    // rejection, flash calmly, and leave the foreign rule untouched.
    $other = User::create([
        'username' => 'tamper-toast-rule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedDivergenceRule($other, $this->streaming->id, 'OTHER-VAL');

    Livewire::test(CorrectionDivergenceToast::class)
        ->set('ruleId', $foreignRuleId)
        ->set('newCategoryId', $this->music->id)
        ->set('visible', true)
        ->call('update')
        ->assertSet('visible', false)
        ->assertSet('flashMessage', 'Rule no longer exists.');

    // The foreign rule's category_id MUST remain unchanged.
    expect(divergenceRuleCategoryId($foreignRuleId))->toBe($this->streaming->id);
});

it('CorrectionDivergenceToast.update() catches InvalidArgumentException for a foreign-category newCategoryId and surfaces a calm flash', function (): void {
    // A tampered event pairing the user's own ruleId with a foreign
    // newCategoryId would re-categorise their transactions into a stranger's
    // category; UpdateCategorizationRule rejects it and the SFC stays calm.
    $other = User::create([
        'username' => 'tamper-toast-cat',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignCategoryId = Category::create([
        'user_id' => $other->id,
        'name' => 'Secret',
        'slug' => 'secret-toast-cat',
        'kind' => 'expense',
        'display_order' => 999,
    ])->id;

    $ownRuleId = seedDivergenceRule($this->user, $this->streaming->id, 'OWN-VAL');

    Livewire::test(CorrectionDivergenceToast::class)
        ->set('ruleId', $ownRuleId)
        ->set('newCategoryId', $foreignCategoryId)
        ->set('visible', true)
        ->call('update')
        ->assertSet('flashMessage', 'Invalid category — please refresh the page.');

    // The user's own rule must NOT have been retargeted at the foreign category.
    expect(divergenceRuleCategoryId($ownRuleId))->toBe($this->streaming->id);
});
