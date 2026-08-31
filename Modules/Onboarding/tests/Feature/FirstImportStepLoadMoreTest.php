<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// The value render() hands the blade, read from where the reader gets it. The
// step used to expose an accessor for the same field, and anything public on a
// Livewire component is reachable by a crafted request.
function loadMorePreview(Testable $component): ConsolidatedPreviewBatch
{
    /** @var ConsolidatedPreviewBatch $preview */
    $preview = $component->viewData('preview');

    return $preview;
}

beforeEach(function (): void {
    // Freeze the clock so the 14-day stale window inside
    // BuildConsolidatedPreviewQuery is deterministic.
    $this->frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($this->frozenNow);
    CarbonImmutable::setTestNow($this->frozenNow);

    $this->app->instance(Clock::class, new class($this->frozenNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

    $this->app->forgetInstance(PreviewCache::class);
    $this->app->forgetInstance(BuildConsolidatedPreviewQuery::class);

    $this->user = User::query()->create([
        'username' => 'load-more',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function seedLoadMoreRunWithRows(int $userId, string $sourceFormat, int $newRowCount): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $userId,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/lmr-'.bin2hex(random_bytes(4)).'.bin',
        'sha256' => hash('sha256', 'lmr-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $rows = [];
    for ($i = 0; $i < $newRowCount; $i++) {
        $rows[] = new PreviewRowDto(
            rowIndex: $i,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            postedAt: '2026-05-10',
            counterpartyName: 'Fixture '.$i,
            counterpartyIban: null,
            description: 'fixture-row-'.$i,
            amountMinor: -1000 - $i,
            currency: 'EUR',
            error: null,
        );
    }

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: $rows,
            accountsToName: [],
        ),
        canonical: [],
        enrichments: [],
    );

    return $run->id;
}

it('grows one section by 25 rows on each click of loadMoreRows', function (): void {
    $bankRunId = seedLoadMoreRunWithRows($this->user->id, 'camt053', 60);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);

    $component = Livewire::test(FirstImportStep::class);

    expect(loadMorePreview($component)->sections[0]->sampleRows)->toHaveCount(5);

    $component->call('loadMoreRows', 'camt053');
    expect(loadMorePreview($component)->sections[0]->sampleRows)->toHaveCount(30);

    $component->call('loadMoreRows', 'camt053');
    expect(loadMorePreview($component)->sections[0]->sampleRows)->toHaveCount(55);

    $component->call('loadMoreRows', 'camt053');
    expect(loadMorePreview($component)->sections[0]->sampleRows)->toHaveCount(60);
});

it('isolates per-section row caps so expanding one section does not grow another', function (): void {
    $bankRunId = seedLoadMoreRunWithRows($this->user->id, 'camt053', 30);
    $paypalRunId = seedLoadMoreRunWithRows($this->user->id, 'paypal-csv', 30);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->update(['data' => json_encode(['paypal_import_run_id' => $paypalRunId])]);

    $component = Livewire::test(FirstImportStep::class);

    $initial = loadMorePreview($component)->sections;
    $initialBySource = [];
    foreach ($initial as $section) {
        $initialBySource[$section->sourceFormat] = $section;
    }
    expect($initialBySource['camt053']->sampleRows)->toHaveCount(5);
    expect($initialBySource['paypal-csv']->sampleRows)->toHaveCount(5);

    $component->call('loadMoreRows', 'camt053');

    $afterClick = loadMorePreview($component)->sections;
    $afterBySource = [];
    foreach ($afterClick as $section) {
        $afterBySource[$section->sourceFormat] = $section;
    }
    expect($afterBySource['camt053']->sampleRows)->toHaveCount(30);
    expect($afterBySource['paypal-csv']->sampleRows)->toHaveCount(5);
});
