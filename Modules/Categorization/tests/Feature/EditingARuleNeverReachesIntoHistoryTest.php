<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Rewriting history is the reader's decision, taken from the rules screen with
// the progress it reports in front of them. A save that quietly re-ran the
// whole walk would rewrite rows they never asked about, on a screen showing
// nothing, and would do it once per keystroke-sized edit.

/**
 * @return array{user: User, transaction: Transaction, streamingId: int, musicId: int}
 */
function ruleEditNoReplayFixtures(): array
{
    $suffix = bin2hex(random_bytes(4));

    $user = User::query()->create([
        'username' => 'rule-edit-no-replay-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'rule-edit-no-replay-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(substr($suffix, 0, 8)),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rule-edit-no-replay.xml',
        'sha256' => hash('sha256', 'rule-edit-no-replay-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $streaming = Category::query()->create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'rule-edit-no-replay-streaming-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $music = Category::query()->create([
        'user_id' => null,
        'name' => 'Music',
        'slug' => 'rule-edit-no-replay-music-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $transaction = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify ab',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rule-edit-no-replay-tx-'.$suffix),
        'fingerprint_version' => 1,
    ]);

    return [
        'user' => $user,
        'transaction' => $transaction,
        'streamingId' => (int) $streaming->id,
        'musicId' => (int) $music->id,
    ];
}

function ruleEditNoReplayCategoryOf(int $transactionId): ?int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $value = $db->connection()->table('transactions')->where('id', $transactionId)->value('category_id');

    return is_numeric($value) ? (int) $value : null;
}

/**
 * @param  list<array<string, mixed>>  $actions
 */
function ruleEditNoReplayInput(array $actions): RuleInput
{
    return new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [
            ['field' => 'counterparty', 'op' => 'equals', 'value_type' => 'string', 'value' => 'Spotify AB'],
        ],
        actions: $actions,
    );
}

beforeEach(function (): void {
    $this->fixtures = ruleEditNoReplayFixtures();
    $this->user = $this->fixtures['user'];
    $this->transactionId = (int) $this->fixtures['transaction']->id;
    $this->create = app(CreateCategorizationRule::class);
    $this->update = app(UpdateCategorizationRule::class);
    $this->remove = app(DeleteCategorizationRule::class);
});

it('leaves an already-imported row alone when a rule that matches it is written and then rewritten', function (): void {
    $ruleId = ($this->create)($this->user, ruleEditNoReplayInput([
        ['type' => 'category', 'payload' => ['category_id' => $this->fixtures['streamingId']]],
    ]));

    expect(ruleEditNoReplayCategoryOf($this->transactionId))->toBeNull();

    ($this->update)($this->user, $ruleId, ruleEditNoReplayInput([
        ['type' => 'category', 'payload' => ['category_id' => $this->fixtures['musicId']]],
    ]));

    expect(ruleEditNoReplayCategoryOf($this->transactionId))->toBeNull();

    ($this->remove)($this->user, $ruleId);

    expect(ruleEditNoReplayCategoryOf($this->transactionId))->toBeNull();
});

// The control for the three assertions above: the same rule, run the way the
// rules screen runs it, does reach this row. Without it they would read the
// same on a row no rule could ever have matched.
it('categorises that row the moment the reader asks the rules screen to re-apply', function (): void {
    ($this->create)($this->user, ruleEditNoReplayInput([
        ['type' => 'category', 'payload' => ['category_id' => $this->fixtures['streamingId']]],
    ]));

    expect(ruleEditNoReplayCategoryOf($this->transactionId))->toBeNull();

    Livewire::actingAs($this->user)
        ->test(RulesPage::class)
        ->call('triggerReapply');

    expect(ruleEditNoReplayCategoryOf($this->transactionId))->toBe($this->fixtures['streamingId']);
});

it('queues no history walk of any kind while a rule is written, rewritten or removed', function (): void {
    Bus::fake();

    $ruleId = ($this->create)($this->user, ruleEditNoReplayInput([
        ['type' => 'category', 'payload' => ['category_id' => $this->fixtures['streamingId']]],
    ]));

    ($this->update)($this->user, $ruleId, ruleEditNoReplayInput([
        ['type' => 'category', 'payload' => ['category_id' => $this->fixtures['musicId']]],
    ]));

    ($this->remove)($this->user, $ruleId);

    Bus::assertNotDispatched(ReapplyRulesJob::class);
    Bus::assertNotDispatchedSync(ReapplyRulesJob::class);
    Bus::assertNotDispatchedAfterResponse(ReapplyRulesJob::class);
});

it('takes the same walk through the dispatcher the rules screen reaches for, so the fake above can see one', function (): void {
    Bus::fake();

    app(BusDispatcher::class)->dispatchSync(new ReapplyRulesJob($this->user->id));

    Bus::assertDispatchedSync(ReapplyRulesJob::class);
});
