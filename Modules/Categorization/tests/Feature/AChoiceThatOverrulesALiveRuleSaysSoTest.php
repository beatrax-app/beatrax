<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Livewire\Livewire;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Http\Livewire\CategorizationProvenancePanel;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The card drew the same three lines whether the reader had accepted the rule
// or overruled it, so the one case worth a sentence — a live rule the reader
// has just disagreed with — arrived looking exactly like agreement.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'overrule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-overrule',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0987654321',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/overrule.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->ruleSays = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming-overrule',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->readerSays = Category::create([
        'user_id' => null,
        'name' => 'Software',
        'slug' => 'software-overrule',
        'kind' => 'expense',
        'display_order' => 101,
    ]);
});

function overruleRule(User $user, int $categoryId, bool $active): int
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    return ($create)(
        $user,
        new RuleInput(
            priority: 10,
            combinator: 'all',
            active: $active,
            notes: null,
            conditions: [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY']],
            actions: [['type' => 'category', 'payload' => ['category_id' => $categoryId]]],
        ),
    );
}

/**
 * @param  array<string, mixed>|null  $provenance
 */
function overruleTransaction(int $userId, int $accountId, int $importRunId, int $categoryId, ?array $provenance): int
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
        'fingerprint' => str_repeat(chr(ord('k') + random_int(0, 5)), 64),
        'fingerprint_version' => 1,
        'category_id' => $categoryId,
    ];
    if ($provenance !== null) {
        $payload['auto_category_provenance'] = $provenance;
    }

    return (int) Transaction::create($payload)->id;
}

it('names both categories when the reader overrules a rule that can still fire', function (): void {
    $ruleId = overruleRule($this->user, $this->ruleSays->id, active: true);
    $txId = overruleTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->readerSays->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->ruleSays->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'rule')
        ->assertSet('divergedFromRuleInto', 'Software')
        ->assertSee('This transaction sits under Software, but the rule is still active and files its matches under Streaming.');
});

it('says nothing when the reader kept the category the rule chose', function (): void {
    $ruleId = overruleRule($this->user, $this->ruleSays->id, active: true);
    $txId = overruleTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->ruleSays->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->ruleSays->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'rule')
        ->assertSet('divergedFromRuleInto', '')
        ->assertSee('Rule that fired')
        ->assertDontSee('but the rule is still active');
});

it('says nothing when the rule the reader overruled can no longer fire', function (): void {
    $ruleId = overruleRule($this->user, $this->ruleSays->id, active: false);
    $txId = overruleTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->readerSays->id,
        ['source' => 'rule', 'rule_id' => $ruleId, 'memory_id' => null, 'category_id' => $this->ruleSays->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'rule')
        ->assertSet('divergedFromRuleInto', '')
        ->assertSee('Rule that fired')
        ->assertDontSee('but the rule is still active');
});

it('stays silent when the choice only diverges from merchant memory', function (): void {
    $txId = overruleTransaction(
        $this->user->id,
        $this->account->id,
        $this->importRun->id,
        $this->readerSays->id,
        ['source' => 'memory', 'rule_id' => null, 'memory_id' => 7, 'category_id' => $this->ruleSays->id],
    );

    Livewire::test(CategorizationProvenancePanel::class, ['transactionId' => $txId])
        ->assertSet('variant', 'memory')
        ->assertSet('divergedFromRuleInto', '')
        ->assertDontSee('but the rule is still active');
});
