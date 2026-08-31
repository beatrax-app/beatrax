<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\TransactionType;

// The one real ICS statement this repo commits states its own deadline in the
// issuer's words on line 57: the minimum payment is expected "voor 8 maart
// 2026", against a period the app derives as 2026-01-15 to 2026-02-12. That is
// 24 days, and the settlement below is posted on exactly the day the statement
// asked for.
/**
 * @link ../../../Ingestion/tests/fixtures/ics/ics-sample-1.md
 */
const PRINTED_DUE_DAY = '2026-03-08';

const PRINTED_CLOSING_MINOR = 141650;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-09 09:00:00');

    /** @var array{user: User, account: Account} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->payer = $seed['account'];
    $this->actingAs($this->user);

    $fixtureTxt = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt');
    $extractorDouble = new class($fixtureTxt) extends PdfTextExtractor
    {
        public function __construct(private readonly string $fixtureTxt)
        {
            parent::__construct();
        }

        public function extract(string $pdfPath): string
        {
            $contents = file_get_contents($this->fixtureTxt);
            if ($contents === false) {
                throw new RuntimeException('Could not read the committed ICS text fixture.');
            }

            return $contents;
        }
    };
    $this->app->instance(PdfTextExtractor::class, $extractorDouble);
    foreach ([SourceAdapterRegistry::class, IcsPdfAdapter::class, ImportPipeline::class, ParseStage::class] as $singleton) {
        $this->app->forgetInstance($singleton);
    }

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $importer->runAndConfirm(
        base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'),
        'ics-pdf',
        $this->user,
    );

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/printed-due.xml',
        'sha256' => str_pad('printeddue', 64, '0'),
        'uploaded_at' => CarbonImmutable::parse(PRINTED_DUE_DAY.' 00:00:00'),
        'status' => 'previewed',
    ]);

    $this->settlementId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->payer->id,
        'type' => TransactionType::TransferOut->value,
        'posted_at' => PRINTED_DUE_DAY,
        'booked_at' => PRINTED_DUE_DAY.' 12:00:00',
        'value_date' => PRINTED_DUE_DAY,
        'amount_minor' => -PRINTED_CLOSING_MINOR,
        'currency' => 'EUR',
        'settled_amount_minor' => -PRINTED_CLOSING_MINOR,
        'settled_currency' => 'EUR',
        'counterparty_iban' => SyntheticIban::IcsCard->value,
        'counterparty_name' => 'ICS Cards Nederland',
        'counterparty_normalized' => 'ics cards nederland',
        'normalization_version' => 3,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('printedduefingerprint', 64, '0'),
        'fingerprint_version' => 3,
        'created_at' => PRINTED_DUE_DAY.' 12:00:00',
        'updated_at' => PRINTED_DUE_DAY.' 12:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('derives the period the committed statement bills, which the printed deadline is 24 days after', function (): void {
    $statement = $this->db->connection()->table('card_statements')
        ->where('user_id', $this->user->id)
        ->first(['period_start', 'period_end', 'open_balance_minor']);

    expect(substr((string) $statement->period_start, 0, 10))->toBe('2026-01-15');
    expect(substr((string) $statement->period_end, 0, 10))->toBe('2026-02-12');
    expect((int) $statement->open_balance_minor)->toBe(PRINTED_CLOSING_MINOR);

    $lag = CarbonImmutable::parse(substr((string) $statement->period_end, 0, 10))
        ->diffInDays(CarbonImmutable::parse(PRINTED_DUE_DAY));

    expect((int) $lag)->toBe(24);
});

it('tells the reader the day the statement itself asked to be paid', function (): void {
    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);

    $tile = $query->forecastTileForUser($this->user);

    expect($tile)->not->toBeNull();
    expect($tile?->dueDate->toDateString())->toBe(PRINTED_DUE_DAY);
});

it('settles the statement from a payment made on the day the statement printed', function (): void {
    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($this->user);

    $links = $this->db->connection()->table('chain_links')
        ->where('user_id', $this->user->id)
        ->where('kind', ChainLinkKind::IcsBulkSettle->value)
        ->where('state', ChainLinkState::Confirmed->value)
        ->where('from_transaction_id', $this->settlementId)
        ->count();

    expect($links)->toBeGreaterThan(0);

    expect((string) $this->db->connection()->table('card_statements')
        ->where('user_id', $this->user->id)
        ->value('state'))->toBe(CardStatementState::Settled->value);
});
