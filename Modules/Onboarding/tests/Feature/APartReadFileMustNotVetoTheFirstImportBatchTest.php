<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// The step confirms every ready run inside one transaction, so a run the
// confirm refuses does not fail alone -- it rolls back every statement staged
// beside it, leaves the section reading READY, and reports one sentence naming
// no file. The reader has no per-run discard, so retrying fails identically.
beforeEach(function (): void {
    $frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($frozenNow);
    CarbonImmutable::setTestNow($frozenNow);

    $this->user = User::query()->create([
        'username' => 'part-read-batch',
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

// A spy rather than the real action: what is under test is which runs the step
// hands over, and ConfirmImport's own refusal is locked where it lives.
function confirmSpyRecordingTheRunsItWasGiven(): object
{
    return new class implements ConfirmsImports
    {
        /** @var list<int> */
        public array $confirmed = [];

        public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
        {
            $this->confirmed[] = $importRunId;

            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 1,
                duplicates: 0,
                enriched: 0,
                errors: 0,
            );
        }
    };
}

// Two card statements dropped together on the connect-card step, which stashes
// whatever it previewed without asking whether the file read whole. One of them
// stopped being read; they share a source format, so they share a section.
function stagePartReadBatch(User $user): array
{
    /** @var Account $cardAccount */
    $cardAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICSCARD',
        'default_currency' => 'EUR',
    ]);

    $cleanRunId = PreviewSeedHelper::seedRunWithPreview($user->id, 'ics-pdf', 3, $cardAccount->id);
    $partReadRunId = PreviewSeedHelper::seedRunWithPreview(
        $user->id,
        'ics-pdf',
        2,
        $cardAccount->id,
        ImportFailureReason::FileStoppedShort,
        'Row 3: A two digit day could not be found.',
    );

    DB::table('wizard_progress')
        ->where('user_id', $user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [$cleanRunId, $partReadRunId]])]);

    return [$cleanRunId, $partReadRunId];
}

it('commits the statements staged beside a file that stopped being read', function (): void {
    [$cleanRunId] = stagePartReadBatch($this->user);

    $spy = confirmSpyRecordingTheRunsItWasGiven();
    $this->app->instance(ConfirmsImports::class, $spy);

    $component = Livewire::test(FirstImportStep::class)->call('commitEverything');

    expect($spy->confirmed)->toBe([$cleanRunId])
        ->and($component->get('commitError'))->toBe('');

    $component->assertDispatched('wizard.step.completed');
});

// The run is not committed and not discarded: it stays previewed, which is what
// lets the reader upload the file again rather than find it silently gone.
it('leaves the part-read run previewed rather than confirming or dropping it', function (): void {
    [, $partReadRunId] = stagePartReadBatch($this->user);

    $this->app->instance(ConfirmsImports::class, confirmSpyRecordingTheRunsItWasGiven());

    Livewire::test(FirstImportStep::class)->call('commitEverything');

    expect(DB::table('import_runs')->where('id', $partReadRunId)->value('status'))->toBe('previewed');
});
