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

    $this->app->forgetInstance(PreviewCache::class);
    $this->app->forgetInstance(BuildConsolidatedPreviewQuery::class);

    $this->user = User::query()->create([
        'username' => 'balance-shape',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Bank',
        'slug' => 'bank',
        'kind' => 'bank',
        'iban' => 'NL95BANK0000000000',
        'default_currency' => 'EUR',
        'starting_balance_minor' => 4242,
        'starting_balance_date' => '2026-01-01',
    ]);

    $runId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 3, $this->account->id);
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $runId])]);

    $this->app->instance(ConfirmsImports::class, new class implements ConfirmsImports
    {
        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 1,
                duplicates: 0,
                enriched: 0,
                errors: 0,
            );
        }
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function accountBalanceRow(int $accountId): object
{
    return DB::table('accounts')
        ->where('id', $accountId)
        ->first(['starting_balance_minor', 'starting_balance_date']);
}

it('commits the import but leaves the opening balance alone when the confirmation is not a balance', function (): void {
    Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [
            (string) $this->account->id => ['minor' => 'not-a-number', 'date' => 'not-a-date'],
        ])
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    $row = accountBalanceRow($this->account->id);
    expect((int) $row->starting_balance_minor)->toBe(4242)
        ->and(substr((string) $row->starting_balance_date, 0, 10))->toBe('2026-01-01');
});

it('leaves the opening balance alone when the confirmation is out of range', function (): void {
    Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [
            (string) $this->account->id => ['minor' => PHP_INT_MAX, 'date' => '2026-04-30'],
        ])
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    expect((int) accountBalanceRow($this->account->id)->starting_balance_minor)->toBe(4242);
});

it('leaves the opening balance alone when the confirmation is dated in the future', function (): void {
    Livewire::test(FirstImportStep::class)
        ->call('onStartingBalanceConfirmed', $this->account->id, 100000, '2099-01-01')
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    expect(substr((string) accountBalanceRow($this->account->id)->starting_balance_date, 0, 10))->toBe('2026-01-01');
});

it('leaves the opening balance alone when the confirmation has no keys at all', function (): void {
    Livewire::test(FirstImportStep::class)
        ->set('balanceConfirmations', [(string) $this->account->id => ['a' => 'b']])
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    expect((int) accountBalanceRow($this->account->id)->starting_balance_minor)->toBe(4242);
});

it('still writes a balance the card could genuinely have confirmed', function (): void {
    Livewire::test(FirstImportStep::class)
        ->call('onStartingBalanceConfirmed', $this->account->id, 100000, '2026-04-30')
        ->call('commitEverything')
        ->assertDispatched('wizard.step.completed');

    $row = accountBalanceRow($this->account->id);
    expect((int) $row->starting_balance_minor)->toBe(100000)
        ->and(substr((string) $row->starting_balance_date, 0, 10))->toBe('2026-04-30');
});
