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
use Modules\Onboarding\Models\WizardProgress;

uses(RefreshDatabase::class);

/*
 * Regression lock for FirstImportStep::commit() — proves that when one
 * ConfirmsImports invocation throws mid-commit, the outer transaction
 * rolls back every earlier write: no transactions land, no
 * starting_balance_minor is set on any account, wizard_progress for
 * 'first-import' stays 'pending', and the Livewire component surfaces
 * the user-facing error band via commitError. wizard.step.completed is
 * NOT dispatched.
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
        'username' => 'commit-rollback',
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

it('rolls back all writes when ConfirmsImports throws mid-commit', function (): void {
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

    // Throwing double — succeeds on the first __invoke, raises on the
    // second. The throw lands AFTER the first ConfirmsImports call has
    // returned, so the outer transaction must roll back the first
    // confirm AND any starting-balance update + wizard_progress flip
    // that would otherwise have followed.
    $this->app->instance(ConfirmsImports::class, new class implements ConfirmsImports
    {
        private int $calls = 0;

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw new RuntimeException('Simulated confirm failure for rollback test.');
            }

            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 1,
                duplicates: 0,
                enriched: 0,
                errors: 0,
            );
        }
    });

    $component = Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [
            (string) $bankAccount->id => ['minor' => 100000, 'date' => '2026-04-30'],
            (string) $cardAccount->id => ['minor' => 50000, 'date' => '2026-04-30'],
        ])
        ->call('commitEverything');

    $component->assertNotDispatched('wizard.step.completed');

    // Nothing landed in the transactions table — the failed second
    // ConfirmsImports call rolled the outer transaction back.
    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);

    // Neither account's starting_balance_minor was applied.
    /** @var Account $bankAfter */
    $bankAfter = Account::query()->findOrFail($bankAccount->id);
    expect($bankAfter->starting_balance_minor)->toBeNull();

    /** @var Account $cardAfter */
    $cardAfter = Account::query()->findOrFail($cardAccount->id);
    expect($cardAfter->starting_balance_minor)->toBeNull();

    // Wizard step stays on 'pending' — the 'done' UPDATE inside the
    // transaction was rolled back with the rest of the writes.
    /** @var WizardProgress|null $progress */
    $progress = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'first-import')
        ->first();
    expect($progress)->not->toBeNull();
    expect($progress->status)->toBe('pending');

    // User-facing inline error band is populated.
    expect($component->get('commitError'))->not->toBe('');
});
