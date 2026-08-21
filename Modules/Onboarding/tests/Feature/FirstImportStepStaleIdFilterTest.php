<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// commit() filters the stash through build() → surviveBoundaryFilters and
// walks only 'ready' sections, so a run older than the 14-day stale window
// never reaches ConfirmsImports. A refactor that inlined the raw stash ids
// into the commit loop would fail here.

beforeEach(function (): void {
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
        'username' => 'stale-id-filter',
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

it('does not call ConfirmsImports for stale import runs (regression-locks BuildConsolidatedPreviewQuery stale-window filter consumed by commit())', function (): void {
    /** @var Account $cardAccount */
    $cardAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICSCARD',
        'default_currency' => 'EUR',
    ]);

    // One inside the 14-day window from the frozen 2026-05-15 now, one 44
    // days outside it.
    $freshRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 2, $cardAccount->id);
    $staleRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 2, $cardAccount->id);

    DB::table('import_runs')
        ->where('id', $staleRunId)
        ->update(['created_at' => '2026-04-01 00:00:00']);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [$freshRunId, $staleRunId]])]);

    // Returns a synthetic success so commit() runs on to the balance writes
    // and the wizard_progress flip.
    $recorder = new class implements ConfirmsImports
    {
        /** @var list<int> */
        public array $received = [];

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            $this->received[] = $importRunId;

            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 1,
                duplicates: 0,
                enriched: 0,
                errors: 0,
            );
        }
    };
    $this->app->instance(ConfirmsImports::class, $recorder);

    Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [(string) $cardAccount->id => ['minor' => 0, 'date' => '2026-04-30']])
        ->call('commitEverything');

    expect($recorder->received)->toBe([$freshRunId]);
});
