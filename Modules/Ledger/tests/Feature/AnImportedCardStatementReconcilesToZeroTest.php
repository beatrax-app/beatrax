<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;

// A card account created by a plain import, not by the onboarding wizard. The
// wizard is the only place that ever asked the reader to confirm an opening
// balance, so this is the account shape the reconcile screen mostly meets.
beforeEach(function (): void {
    /** @var array{user: User, icsAccount: Account} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
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
                throw new RuntimeException('Could not read ICS text fixture.');
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

    $this->icsAccount = Account::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_card')
        ->firstOrFail();
});

it('anchors the account on the opening balance the imported statement carries', function (): void {
    /** @var Account $account */
    $account = $this->icsAccount->fresh();

    expect($account->starting_balance_minor)->toBe(-60696);
});

// The opening balance precedes every row the statement brought, and 2026-01-15
// is the earliest of them. The cycle used to open on the 16th -- the earliest
// BOEKDATUM -- and anchoring there excluded EUR62.40 the closing figure counts;
// the ICS period is derived from posted_at now, so the two agree and the guard
// in anchorDate() has nothing left to correct on this format.
it('anchors on the earliest row the statement brought', function (): void {
    /** @var Account $account */
    $account = $this->icsAccount->fresh();

    expect((string) $account->starting_balance_date)->toStartWith('2026-01-15');
});

// -60696 is the statement's own `Vorig openstaand saldo`. Anchored at zero
// instead, the screen showed exactly that as a difference the reader was told
// to close by toggling rows, and no set of rows could close it.
it('reconciles the imported statement to a zero difference', function (): void {
    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->icsAccount->id]);

    expect($page->viewData('differenceMinor'))->toBe(0);
    expect($page->viewData('isMatched'))->toBeTrue();
});

it('keeps the prefilled target and cleared balance agreeing on the statement closing figure', function (): void {
    $page = Livewire::test(ReconcilePage::class, ['accountId' => $this->icsAccount->id]);

    expect($page->viewData('statementTargetMinor'))->toBe(-141650);
    expect($page->viewData('clearedBalanceMinor'))->toBe(-141650);
});

it('completes the reconcile the arithmetic now permits', function (): void {
    Livewire::test(ReconcilePage::class, ['accountId' => $this->icsAccount->id])
        ->call('confirmReconcile')
        ->assertSet('error', '');
});
