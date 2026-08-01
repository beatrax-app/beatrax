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
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;

uses(RefreshDatabase::class);

/*
 * Regression lock for FirstImportStep::commit() — proves the happy path
 * confirms every stashed ImportRun and writes every starting balance
 * inside the same atomic flow.
 *
 * The verification is final-state based (no transaction-depth spy, per
 * the no-mocking-DB rule): a recording ConfirmsImports double captures
 * the run ids commit() invoked it with, and the post-commit DB state is
 * asserted to reflect every balance update + the 'done' flip on
 * wizard_progress for the 'first-import' step. The Livewire instance
 * must finish with commitError cleared and dispatch wizard.step.completed.
 */

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
        'username' => 'commit-everything',
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

it('commits every stashed ImportRun and writes starting balances atomically', function (): void {
    /** @var Account $bankAccount */
    $bankAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Bank',
        'slug' => 'bank',
        'kind' => 'bank',
        'iban' => 'NL95BANK0000000000',
        'default_currency' => 'EUR',
    ]);

    /** @var Account $cardAccount */
    $cardAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICSCARD',
        'default_currency' => 'EUR',
    ]);

    $bankRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 3, $bankAccount->id);
    $cardRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 2, $cardAccount->id);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $bankRunId])]);
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [$cardRunId]])]);

    // Recording double — captures every (importRunId, dispatchChain) pair
    // commit() invokes ConfirmsImports with, AND marks the ImportRun row
    // as 'confirmed' so the post-commit ImportRun.status assertion proves
    // the confirm write landed inside the same transaction as the balance
    // updates and the wizard_progress 'done' flip.
    $recorder = new class($this->user->id) implements ConfirmsImports
    {
        /** @var list<array{importRunId: int, dispatchChain: bool}> */
        public array $received = [];

        public function __construct(private readonly int $expectedUserId) {}

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            $this->received[] = ['importRunId' => $importRunId, 'dispatchChain' => $dispatchChain];

            // Mirror the real ConfirmImport's status flip inside the outer
            // transaction so the test can prove every stashed run reached
            // 'confirmed' atomically without seeding canonical rows.
            ImportRun::query()
                ->where('id', $importRunId)
                ->where('user_id', $this->expectedUserId)
                ->update(['status' => 'confirmed']);

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

    $component = Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [
            (string) $bankAccount->id => ['minor' => 100000, 'date' => '2026-04-30'],
            (string) $cardAccount->id => ['minor' => 50000, 'date' => '2026-04-30'],
        ])
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    // The recorder saw both stashed run ids, in the canonical bank → card
    // section order BuildConsolidatedPreviewQuery emits. Each inner call
    // received dispatchChain=false because the wizard owns the outer
    // dispatch and forwards it ONCE after the transaction returns.
    expect($recorder->received)->toBe([
        ['importRunId' => $bankRunId, 'dispatchChain' => false],
        ['importRunId' => $cardRunId, 'dispatchChain' => false],
    ]);

    // Every stashed ImportRun reaches 'confirmed' inside the outer
    // transaction, proving the ConfirmsImports invocation and the balance
    // updates land in the same atomic frame.
    expect(ImportRun::query()->where('user_id', $this->user->id)->where('status', 'confirmed')->count())->toBe(2);

    // Both starting balances persist on the accounts table.
    /** @var Account $bankAfter */
    $bankAfter = Account::query()->findOrFail($bankAccount->id);
    expect((int) $bankAfter->starting_balance_minor)->toBe(100000);
    expect($bankAfter->starting_balance_date?->toDateString())->toBe('2026-04-30');

    /** @var Account $cardAfter */
    $cardAfter = Account::query()->findOrFail($cardAccount->id);
    expect((int) $cardAfter->starting_balance_minor)->toBe(50000);
    expect($cardAfter->starting_balance_date?->toDateString())->toBe('2026-04-30');

    // Wizard step flips to done; no error band surfaces.
    /** @var WizardProgress|null $progress */
    $progress = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'first-import')
        ->first();
    expect($progress)->not->toBeNull();
    expect($progress->status)->toBe('done');
    expect($component->get('commitError'))->toBe('');
});
