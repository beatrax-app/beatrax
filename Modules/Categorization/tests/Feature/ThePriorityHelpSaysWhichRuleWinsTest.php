<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// The form's help line is the only place a rule author is told which of two
// competing rules lands. It said "lower numbers run first" and stopped there —
// true, and the exact half that invites the wrong conclusion, because the last
// rule visited is the one whose action survives.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:00:00'));

    $this->user = User::create([
        'username' => 'priority-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function priorityRule(int $userId, int $priority, int $categoryId): CategorizationRule
{
    $rule = CategorizationRule::create([
        'user_id' => $userId,
        'priority' => $priority,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);

    $rule->conditions()->create([
        'field' => 'counterparty',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'Spotify',
        'value2' => null,
    ]);

    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => $categoryId],
    ]);

    return $rule;
}

it('lets the highest priority number win when two rules set the same field', function (): void {
    $account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'priority-help-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/priority-help.xml',
        'sha256' => hash('sha256', 'priority-help-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify ab',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'priority-help-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
    ]);

    $broad = Category::create(['user_id' => null, 'name' => 'Subscriptions', 'slug' => 'ph-subs-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);
    $override = Category::create(['user_id' => null, 'name' => 'Entertainment', 'slug' => 'ph-ent-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 2]);

    priorityRule($this->user->id, 5, $broad->id);
    priorityRule($this->user->id, 9, $override->id);

    $matched = app(RuleEngine::class)->match(new RuleMatchInput(
        counterpartyName: 'Spotify AB',
        description: 'Music subscription',
        settledAmountMinor: -1099,
        settledCurrency: 'EUR',
        postedAt: CarbonImmutable::parse('2026-07-05'),
    ), $this->user);

    app(RuleApplier::class)->applyAtReapply($matched, $tx->id, $this->user->id);

    $tx->refresh();
    expect($tx->category_id)->toBe($override->id);
});

it('says so on the form the rule author is looking at', function (): void {
    $help = require base_path('Modules/Categorization/Resources/lang/en/rule_form.php');

    expect($help['priority_help'])->toContain('highest number wins');
});

// A locale left on the old sentence is a reader still being told the opposite
// of what the engine does, and it renders identically to a translated one.
it('says so in every language the form is offered in', function (): void {
    $root = base_path('Modules/Categorization/Resources/lang');
    $english = (require $root.'/en/rule_form.php')['priority_help'];

    $unlocalised = [];
    foreach (array_diff((array) scandir($root), ['.', '..', 'en']) as $locale) {
        $file = $root.'/'.$locale.'/rule_form.php';
        if (! is_file($file)) {
            continue;
        }

        $translated = (require $file)['priority_help'];
        if ($translated === '' || $translated === $english) {
            $unlocalised[] = $locale;
        }
    }

    expect($unlocalised)->toBe([]);
});
