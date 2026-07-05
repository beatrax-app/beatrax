<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\CategorizationProvenancePanel;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
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
        'kind' => 'asn',
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

function seedProvenanceRule(User $user, int $categoryId, string $value = 'SPOTIFY'): int
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    return ($create)(
        $user,
        10,
        'all',
        true,
        null,
        [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => $value]],
        [['type' => 'category', 'payload' => ['category_id' => $categoryId]]],
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
    // Cross-tab race: the panel hydrated with $ruleId=$ruleId from
    // the prior render, then the rule was deleted out-of-band before
    // the user clicked Remove rule. DeleteCategorizationRule sees no
    // visible row and throws NotFoundHttpException. The component
    // must catch it, surface a calm flash, and re-hydrate the panel
    // — never a 500 / framework error page.
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
    DB::table('categorization_rules')->where('id', $ruleId)->delete();

    $component
        ->call('confirmRemove')
        ->call('removeRule')
        ->assertSet('variant', 'none')
        ->assertSet('confirmingRemove', false)
        ->assertSet('flashMessage', 'Rule no longer exists (it may have been deleted in another tab).');
});

it('removeRule catches NotFoundHttpException when the panel carries a foreign-user ruleId', function (): void {
    // Tampered Livewire payload posture: the panel state carries a
    // ruleId that belongs to another user. DeleteCategorizationRule's
    // user-scoped lookup rejects it with NotFoundHttpException. The
    // component must catch it; the foreign row MUST remain untouched.
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

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->set('ruleId', $foreignRuleId)
        ->set('variant', 'rule')
        ->call('confirmRemove')
        ->call('removeRule')
        ->assertSet('flashMessage', 'Rule no longer exists (it may have been deleted in another tab).');

    // The foreign rule MUST remain untouched.
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

it('dispatches inline-category-picker:open from the memory variant overrideMemory action', function (): void {
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        ['source' => 'memory', 'rule_id' => null, 'memory_id' => 7, 'category_id' => $this->streaming->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->call('overrideMemory')
        ->assertDispatched('inline-category-picker:open');
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

    // Delete the rule directly; provenance JSON still references it.
    DB::table('categorization_rules')->where('id', $ruleId)->delete();

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'none');
});

it('hydrateFromProvenance renders the none variant when auto_category_provenance is corrupt JSON', function (): void {
    // Best-effort-audit contract: a corrupt provenance payload must
    // NOT crash the transaction detail page. JSON_THROW_ON_ERROR +
    // JsonException catch falls back to the 'none' variant so the
    // panel renders empty.
    $txId = seedProvTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->streaming->id,
        null,
    );

    // Poison the column with a non-JSON string. The raw query builder
    // bypasses the Eloquent cast so the corrupt bytes actually land on
    // disk.
    DB::table('transactions')->where('id', $txId)->update([
        'auto_category_provenance' => '{not valid json',
    ]);

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'none')
        ->assertSet('ruleId', null);
});

it('the app layout @auth block mounts the three global SFCs', function (): void {
    $layoutPath = base_path('resources/views/layouts/app.blade.php');
    $contents = (string) file_get_contents($layoutPath);

    expect($contents)->toContain("@livewire('categorization.rule-form-modal')");
    expect($contents)->toContain("@livewire('categorization.correction-divergence-toast')");
    expect($contents)->toContain("@livewire('receipts.receipt-conflict-toast')");
});

it('transaction-detail.blade.php embeds the categorization-provenance-panel', function (): void {
    $bladePath = base_path('Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php');
    $contents = (string) file_get_contents($bladePath);

    expect($contents)->toContain('categorization.categorization-provenance-panel');
});
