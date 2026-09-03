<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Http\Livewire\CategorizationProvenancePanel;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'prov',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-prov',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('p', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming-prov',
        'kind' => 'expense',
        'display_order' => 100,
    ]);
});

// The rule's own conditions and actions go with it, because the database no
// longer takes them away behind the delete.
function removeProvenanceRule(User $user, int $ruleId): void
{
    /** @var DeleteCategorizationRule $delete */
    $delete = Container::getInstance()->make(DeleteCategorizationRule::class);

    ($delete)($user, $ruleId);
}

function seedProvenanceRule(User $user, int $categoryId, string $value = 'SPOTIFY'): int
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

function seedProvTransaction(int $userId, int $accountId, int $importRunId, int $categoryId, ?array $provenance): int
{
    $payload = [
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
        'fingerprint' => str_repeat(chr(ord('e') + random_int(0, 5)), 64),
        'fingerprint_version' => 1,
        'category_id' => $categoryId,
    ];
    if ($provenance !== null) {
        $payload['auto_category_provenance'] = $provenance;
    }
    $tx = Transaction::create($payload);

    return (int) $tx->id;
}

it('renders the rule variant when provenance.source === rule', function (): void {
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'rule')
        ->assertSet('ruleId', $ruleId)
        ->assertSee('Rule that fired')
        ->assertSee('SPOTIFY')
        ->assertSee('Streaming')
        ->assertSee('Update rule')
        ->assertSee('Remove rule');
});

it('renders the memory variant when provenance.source === memory', function (): void {
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'memory', 'rule_id' => null, 'memory_id' => 7, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'memory')
        ->assertSee('Auto-categorized from merchant history')
        ->assertSee('Override');
});

it('renders nothing when provenance is null (manual categorization)', function (): void {
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        null,
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'none')
        ->assertDontSee('Rule that fired')
        ->assertDontSee('Auto-categorized from merchant history');
});

it('toggles the two-step inline remove-confirmation flow on the rule variant', function (): void {
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('confirmingRemove', false)
        ->call('confirmRemove')
        ->assertSet('confirmingRemove', true)
        ->assertSee('Remove?')
        ->assertSee('Yes, remove')
        ->call('cancelRemove')
        ->assertSet('confirmingRemove', false);
});

it('removes the rule when removeRule is invoked and flips the panel to none variant', function (): void {
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->call('confirmRemove')
        ->call('removeRule')
        ->assertSet('variant', 'none')
        ->assertSet('confirmingRemove', false);

    expect(DB::table('categorization_rules')->where('id', $ruleId)->exists())->toBeFalse();
});

it('removeRule catches NotFoundHttpException when the rule was deleted in another tab and surfaces a calm flash', function (): void {
    // Cross-tab race: the panel hydrated with a ruleId that was deleted
    // out-of-band before Remove was clicked. The component must catch the
    // NotFoundHttpException and re-hydrate rather than serve a 500.
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    $component = Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'rule')
        ->assertSet('ruleId', $ruleId);

    // Simulate the cross-tab race: the row vanishes between renders.
    removeProvenanceRule($this->user, $ruleId);

    $component
        ->call('confirmRemove')
        ->call('removeRule')
        ->assertSet('variant', 'none')
        ->assertSet('confirmingRemove', false)
        ->assertSet('flashMessage', 'Rule no longer exists (it may have been deleted in another tab).');
});

it('refuses a payload naming another reader\'s rule, rather than catching it after the fact', function (): void {
    // ruleId is derived from the row's own provenance and is #[Locked], so a
    // foreign id can no longer reach removeRule() at all. The user-scoped
    // lookup behind it stays as the second line; this pins the first.
    $other = User::create([
        'username' => 'prov-tamper',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedProvenanceRule($other, $this->streaming->id, 'NETFLIX');

    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        null,
    );

    $refused = false;
    try {
        Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
            ->set('ruleId', $foreignRuleId);
    } catch (CannotUpdateLockedPropertyException) {
        $refused = true;
    }

    expect($refused)->toBeTrue('A browser can still name another reader\'s rule on this panel.');
    expect(DB::table('categorization_rules')->where('id', $foreignRuleId)->exists())->toBeTrue();
});

it('dispatches rule-form:open with the ruleId when updateRule is clicked', function (): void {
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->call('updateRule')
        ->assertDispatched('rule-form:open');
});

// The old version of this asserted `inline-category-picker:open` was
// dispatched. It always passed, and the button it covered did nothing: the
// picker mounts per row on the transactions list and declares no listener,
// so on the detail page nothing was on the other end. Assert the picker is
// on screen instead — that is the thing the user is owed.
it('puts the category picker on screen when the memory card Override is used', function (): void {
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'memory', 'rule_id' => null, 'memory_id' => 7, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertDontSeeLivewire('categorization.inline-category-picker')
        ->call('overrideMemory')
        ->assertSet('overrideCategoryId', $this->streaming->id)
        ->assertSeeLivewire('categorization.inline-category-picker');
});

it('falls back to none variant when the referenced rule has been deleted', function (): void {
    $ruleId = seedProvenanceRule($this->user, $this->streaming->id);
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->streaming->id],
    );

    // Delete the rule; provenance JSON still references it.
    removeProvenanceRule($this->user, $ruleId);

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'none');
});

it('hydrateFromProvenance renders the none variant when auto_category_provenance is corrupt JSON', function (): void {
    // Provenance is best-effort audit metadata: a corrupt payload falls back
    // to the 'none' variant rather than crashing the detail page.
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        null,
    );

    // The raw query builder bypasses the Eloquent cast, so the corrupt bytes
    // genuinely land on disk.
    DB::table('transactions')->where('id', $txId)->update([
        'auto_category_provenance' => '{not valid json',
    ]);

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'none')
        ->assertSet('ruleId', null);
});

it('the app layout @auth block mounts the global SFCs', function (): void {
    $layoutPath = base_path('resources/views/layouts/app.blade.php');
    $contents = (string) file_get_contents($layoutPath);

    expect($contents)->toContain("@livewire('categorization.rule-form-modal')");
    expect($contents)->toContain("@livewire('receipts.receipt-conflict-toast')");
});

it('the app layout no longer mounts a second surface for the divergence conversation', function (): void {
    // A layout mount is a Livewire component built on every authenticated page
    // render. The toast that used to sit here asked "update the rule?" detached
    // from the correction that raised the question; the panel asks it inline on
    // the transaction, and one conversation gets one surface.
    $contents = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($contents)->not->toContain('correction-divergence-toast');
});

it('transaction-detail.blade.php embeds the categorization-provenance-panel', function (): void {
    $bladePath = base_path('Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php');
    $contents = (string) file_get_contents($bladePath);

    expect($contents)->toContain('categorization.categorization-provenance-panel');
});
