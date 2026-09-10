<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

// field_provenance is stamped by the manual-assign action, the split editor and
// the rule applier. The cash book's own form and the migration importer both
// write a category the reader chose and stamp nothing, and the column was added
// to a table that already had rows with no backfill behind it.
function unclaimedCategoryRunReapply(int $userId): void
{
    /** @var ReapplyRulesJob $job */
    $job = app(ReapplyRulesJob::class, ['userId' => $userId]);
    $job->handle(
        app(RuleEngine::class),
        app(RuleApplier::class),
        app(TransactionStatusQuery::class),
        app(DatabaseManager::class),
        app(CacheRepository::class),
        app(Clock::class),
        app(LoggerInterface::class),
        app(SensitiveColumnCodec::class),
        app(Session::class),
        app(AppLockKeyService::class),
    );
}

beforeEach(function (): void {
    $this->reader = User::query()->create([
        'username' => 'unclaimed-category-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->reader->id,
        'name' => 'Cash book',
        'slug' => 'unclaimed-cash-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->reader->id,
        'source_format' => 'manual',
        'raw_file_path' => '/tmp/unclaimed-category.dat',
        'sha256' => hash('sha256', 'unclaimed-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $this->chosen = Category::query()->create([
        'user_id' => null,
        'name' => 'Chosen by the reader',
        'slug' => 'unclaimed-chosen-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->byRule = Category::query()->create([
        'user_id' => null,
        'name' => 'Chosen by a rule',
        'slug' => 'unclaimed-rule-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $rule = CategorizationRule::query()->create([
        'user_id' => $this->reader->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'counterparty',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Bakker',
        'value2' => null,
    ]);
    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $this->byRule->id]]);

    $this->write = function (array $overrides): Transaction {
        static $row = 0;
        $row++;

        return Transaction::query()->create(array_merge([
            'user_id' => $this->reader->id,
            'account_id' => $this->account->id,
            'type' => 'expense',
            'posted_at' => '2026-07-05',
            'booked_at' => '2026-07-05 12:0'.$row.':00',
            'value_date' => '2026-07-05',
            'amount_minor' => -1000,
            'currency' => 'EUR',
            'settled_amount_minor' => -1000,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Bakker',
            'counterparty_normalized' => 'bakker',
            'normalization_version' => 1,
            'source_format' => 'manual',
            'import_run_id' => $this->run->id,
            'source_row_index' => $row,
            'category_id' => $this->chosen->id,
            'fingerprint' => str_pad('unclaimed-'.$row, 64, '0', STR_PAD_LEFT),
            'fingerprint_version' => 1,
        ], $overrides));
    };
});

it('leaves a cash-book category the reader picked where a rule would overwrite it', function (): void {
    $tx = ($this->write)([]);

    unclaimedCategoryRunReapply($this->reader->id);

    expect(DB::table('transactions')->where('id', $tx->id)->value('category_id'))
        ->toBe($this->chosen->id);
});

it('still lets a rule replace the category an earlier rule assigned', function (): void {
    $tx = ($this->write)([
        'auto_category_provenance' => [
            'source' => 'rule',
            'rule_id' => 0,
            'memory_id' => null,
            'category_id' => $this->chosen->id,
        ],
    ]);

    unclaimedCategoryRunReapply($this->reader->id);

    expect(DB::table('transactions')->where('id', $tx->id)->value('category_id'))
        ->toBe($this->byRule->id);
});
