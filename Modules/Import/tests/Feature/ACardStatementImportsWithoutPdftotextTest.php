<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Transaction;

// iOS and Android ship no pdftotext and no way to add one. The extractor asks
// that question in exactly one place — whether the poppler binary it would run
// resolves to something executable — so a path that cannot exist puts the
// device's own branch under test on a developer host that does have poppler.
beforeEach(function (): void {
    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);

    $this->app->bind(
        PdfTextExtractor::class,
        static fn (): PdfTextExtractor => new PdfTextExtractor('/nonexistent/beatrax-has-no-pdftotext'),
    );
    // The adapter is reachable from three other singletons, so all of them go
    // before the rebound extractor can reach it.
    foreach ([SourceAdapterRegistry::class, IcsPdfAdapter::class, ImportPipeline::class, ParseStage::class] as $singleton) {
        $this->app->forgetInstance($singleton);
    }

    $this->statement = base_path('Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf');
});

/**
 * @link ../../../Chains/tests/fixtures/scenario-1/scenario-1.md
 */
it('reads every transaction out of the statement PDF with no pdftotext installed', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);

    $result = $importer->runAndConfirm($this->statement, 'ics-pdf', $this->user);

    expect($result->inserted)->toBe(23);
    expect(Transaction::count())->toBe(23);
})->group('phase-3');

it('settles the statement to the total its fixture contract records', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);

    $importer->runAndConfirm($this->statement, 'ics-pdf', $this->user);

    expect((int) Transaction::query()->sum('settled_amount_minor'))->toBe(-84732);
})->group('phase-3');

it('reads the two foreign-currency rows at their own currency, not the euro column', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);

    $importer->runAndConfirm($this->statement, 'ics-pdf', $this->user);

    /** @var Transaction $blueBottle */
    $blueBottle = Transaction::query()->where('counterparty_name', 'LIKE', '%BLUE BOTTLE%')->firstOrFail();

    expect($blueBottle->currency)->toBe('USD');
    expect($blueBottle->amount_minor)->toBe(-1299);
    expect($blueBottle->settled_amount_minor)->toBe(-1207);
    expect($blueBottle->settled_currency)->toBe('EUR');
    expect(Transaction::query()->where('currency', 'USD')->count())->toBe(2);
})->group('phase-3');
