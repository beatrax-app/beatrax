<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

/*
 * Confirms FirstImportStep reads the stashed ImportRun ids out of the
 * `wizard_progress.data` JSON column on mount and feeds them into the
 * shared BuildConsolidatedPreviewQuery — the read-side surface Plan 07
 * shipped — so the consolidated preview renders one section per
 * source format with the locked eyebrow copy.
 *
 * Two behaviours:
 *
 *  1. On mount the step reads `bank_import_run_id` + `card_import_run_ids[]`
 *     out of WizardProgress and the resulting consolidated preview
 *     section count matches the source-format groups in the stash.
 *  2. The rendered HTML carries the per-source eyebrow copy locked
 *     by UI-SPEC G7 ("FROM YOUR BANK STATEMENT" / "FROM YOUR ICS
 *     CARD STATEMENTS").
 */

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
        'username' => 'consolidated-load',
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

function seedRunWithPreview(int $userId, string $sourceFormat, int $newRowCount): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $userId,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/cpl-'.bin2hex(random_bytes(4)).'.bin',
        'sha256' => hash('sha256', 'cpl-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $rows = [];
    for ($i = 0; $i < $newRowCount; $i++) {
        $rows[] = new PreviewRowDto(
            rowIndex: $i,
            status: 'new',
            accountId: 1,
            bookedAt: '2026-05-10',
            counterpartyName: 'Fixture '.$i,
            counterpartyIban: null,
            description: 'fixture-row-'.$i,
            categoryName: null,
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

it('loads stashed bank_import_run_id and card_import_run_ids from wizard_progress on mount', function (): void {
    $bankRunId = seedRunWithPreview($this->user->id, 'asn-camt053', 3);
    $cardRunId1 = seedRunWithPreview($this->user->id, 'ics-pdf', 2);
    $cardRunId2 = seedRunWithPreview($this->user->id, 'ics-pdf', 1);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [$cardRunId1, $cardRunId2]])]);

    $component = Livewire::test(FirstImportStep::class);

    /** @var FirstImportStep $instance */
    $instance = $component->instance();
    $preview = $instance->currentPreview();

    // Two sections — one CAMT bank source, one ICS card source.
    expect($preview->sections)->toHaveCount(2);
    expect($preview->dedupedTotalCount)->toBe(6); // 3 + 2 + 1
});

it('renders a consolidated preview section per source with the locked eyebrow copy', function (): void {
    $bankRunId = seedRunWithPreview($this->user->id, 'asn-camt053', 1);
    $cardRunId = seedRunWithPreview($this->user->id, 'ics-pdf', 1);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [$cardRunId]])]);

    Livewire::test(FirstImportStep::class)
        ->assertSee('FROM YOUR BANK STATEMENT')
        ->assertSee('FROM YOUR ICS CARD STATEMENTS');
});
