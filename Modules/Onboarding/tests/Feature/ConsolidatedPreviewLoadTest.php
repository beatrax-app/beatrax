<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

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

it('loads stashed bank_import_run_id and card_import_run_ids from wizard_progress on mount', function (): void {
    $bankRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 3);
    $cardRunId1 = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 2);
    $cardRunId2 = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 1);

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

    expect($preview->sections)->toHaveCount(2);
    expect($preview->dedupedTotalCount)->toBe(6); // 3 + 2 + 1
});

it('renders a consolidated preview section per source with the locked eyebrow copy', function (): void {
    $bankRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 1);
    $cardRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 1);

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
