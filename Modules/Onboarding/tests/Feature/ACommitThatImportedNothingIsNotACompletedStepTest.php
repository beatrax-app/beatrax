<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// Losing ONE staged run is deliberate: the confirm is offered only runs the
// consolidated preview said it would take, so a refusal that late is a preview
// cache that expired mid-review and one file must not veto the rest. Losing
// every one of them is the other thing, and it reported a finished import.

beforeEach(function (): void {
    $frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($frozenNow);
    CarbonImmutable::setTestNow($frozenNow);

    $this->app->instance(Clock::class, new class($frozenNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

    $this->user = User::query()->create([
        'username' => 'all-runs-refused',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->stageTwoReadyRuns = function (): array {
        /** @var Account $bank */
        $bank = Account::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Bank',
            'slug' => 'bank',
            'kind' => 'bank',
            'iban' => 'NL95BANK0000000000',
            'default_currency' => 'EUR',
        ]);

        /** @var Account $card */
        $card = Account::query()->create([
            'user_id' => $this->user->id,
            'name' => 'ICS card',
            'slug' => 'ics-card',
            'kind' => 'ics_card',
            'iban' => 'ICSCARD',
            'default_currency' => 'EUR',
        ]);

        $bankRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 3, $bank->id);
        $cardRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 2, $card->id);

        DB::table('wizard_progress')
            ->where('user_id', $this->user->id)
            ->where('step_key', 'connect-bank')
            ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);
        DB::table('wizard_progress')
            ->where('user_id', $this->user->id)
            ->where('step_key', 'connect-card')
            ->update(['data' => json_encode(['card_import_run_ids' => [$cardRunId]])]);

        return ['bank' => $bankRunId, 'card' => $cardRunId];
    };

    $this->refuseEveryRun = fn (): ConfirmsImports => new class implements ConfirmsImports
    {
        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            throw new PreviewExpiredException($importRunId);
        }
    };

    $this->firstImportStatus = fn (): mixed => DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'first-import')
        ->value('status');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// The denominator. Without it a run that was never offered to the confirm at
// all would satisfy every case below by refusing nothing.
it('offers both staged runs to the confirm', function (): void {
    $staged = ($this->stageTwoReadyRuns)();

    $this->app->instance(ConfirmsImports::class, new class implements ConfirmsImports
    {
        /** @var list<int> */
        public array $offered = [];

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            $this->offered[] = $importRunId;

            throw new PreviewExpiredException($importRunId);
        }
    });

    Livewire::test(FirstImportStep::class)->call('commitEverything');

    /** @var object{offered: list<int>} $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);

    expect($confirmer->offered)->toEqualCanonicalizing([$staged['bank'], $staged['card']]);
});

it('does not complete the step when every staged run was refused', function (): void {
    ($this->stageTwoReadyRuns)();

    $this->app->instance(ConfirmsImports::class, ($this->refuseEveryRun)());

    $component = Livewire::test(FirstImportStep::class)->call('commitEverything');

    $component->assertNotDispatched('wizard.step.completed');

    expect(($this->firstImportStatus)())->toBe('pending');
});

it('says nothing was changed rather than that there was nothing to commit', function (): void {
    ($this->stageTwoReadyRuns)();

    $this->app->instance(ConfirmsImports::class, ($this->refuseEveryRun)());

    $component = Livewire::test(FirstImportStep::class)->call('commitEverything');

    expect($component->get('commitError'))
        ->toBe(Lang::get('onboarding::first_import.errors.commit_failed'))
        ->not->toBe(Lang::get('onboarding::first_import.errors.nothing_to_commit'));
});

// Not committed and not discarded: the sentence tells the reader to try again,
// and re-rendering the step is what raises the per-source re-upload badge.
it('leaves every refused run previewed so the reader can upload it again', function (): void {
    $staged = ($this->stageTwoReadyRuns)();

    $this->app->instance(ConfirmsImports::class, ($this->refuseEveryRun)());

    Livewire::test(FirstImportStep::class)->call('commitEverything');

    foreach ($staged as $runId) {
        expect(DB::table('import_runs')->where('id', $runId)->value('status'))->toBe('previewed');
    }

    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('still commits the runs staged beside a single refused one, and still completes the step', function (): void {
    $staged = ($this->stageTwoReadyRuns)();

    $real = $this->app->make(ConfirmsImports::class);

    $this->app->instance(ConfirmsImports::class, new class($real, $staged['card']) implements ConfirmsImports
    {
        public function __construct(
            private readonly ConfirmsImports $real,
            private readonly int $refusedRunId,
        ) {}

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            if ($importRunId === $this->refusedRunId) {
                throw new PreviewExpiredException($importRunId);
            }

            return ($this->real)($importRunId, $user, $dispatchChain);
        }
    });

    $component = Livewire::test(FirstImportStep::class)->call('commitEverything');

    expect($component->get('commitError'))->toBe('');
    $component->assertDispatched('wizard.step.completed');

    expect(DB::table('import_runs')->where('id', $staged['bank'])->value('status'))->toBe('confirmed')
        ->and(DB::table('import_runs')->where('id', $staged['card'])->value('status'))->toBe('previewed')
        ->and(($this->firstImportStatus)())->toBe('done');
});
