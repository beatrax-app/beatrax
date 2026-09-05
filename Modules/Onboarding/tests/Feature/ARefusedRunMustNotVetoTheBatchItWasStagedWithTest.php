<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Enums\ConfirmRefusal;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

uses(RefreshDatabase::class);

// The step confirms every run of every READY section inside ONE transaction,
// so a run ConfirmImport refuses does not fail alone: it rolls back every
// statement staged beside it and reports one sentence naming no file. The
// consolidated query was left the job of offering only runs the confirm will
// take, and it read one of the three refusals.
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
        'username' => 'refused-run-batch',
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

// Five card statements dropped on the connect-card step, which stashes whatever
// it previewed without asking whether the confirm would take it, plus a bank
// statement in a section of its own. Four of the five are refusable, one for
// each way a run can be, and the fifth is the file that has to survive them.
/**
 * @return array{healthy: list<int>, refused: list<int>, empty: int}
 */
function stagedBatchCoveringEveryRefusal(User $user): array
{
    /** @var Account $card */
    $card = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICSCARD',
        'default_currency' => 'EUR',
    ]);

    /** @var Account $bank */
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Bank',
        'slug' => 'bank',
        'kind' => 'bank',
        'iban' => 'NL95BANK0000000000',
        'default_currency' => 'EUR',
    ]);

    $healthyCard = PreviewSeedHelper::seedRunWithPreview($user->id, 'ics-pdf', 3, $card->id);
    $healthyBank = PreviewSeedHelper::seedRunWithPreview($user->id, 'camt053', 2, $bank->id);

    $partRead = PreviewSeedHelper::seedRunWithPreview(
        $user->id,
        'ics-pdf',
        2,
        $card->id,
        ImportFailureReason::FileStoppedShort,
        'Row 3: A two digit day could not be found.',
    );

    $unnamedAccount = PreviewSeedHelper::seedRunWithPreview(
        $user->id,
        'ics-pdf',
        2,
        $card->id,
        accountsToName: [new UnknownIban('NL02UNKN0000000000', 'Someone')],
    );

    $everyRowFailed = PreviewSeedHelper::seedRunWithPreview(
        $user->id,
        'ics-pdf',
        0,
        $card->id,
        errorRowCount: 2,
    );

    // No preview at all: the 30-minute cache outlived the review. The other
    // three are refusals ConfirmImport names; this one it cannot even read.
    /** @var ImportRun $expired */
    $expired = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/expired-'.bin2hex(random_bytes(4)).'.pdf',
        'sha256' => hash('sha256', 'expired-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $emptyFile = PreviewSeedHelper::seedRunWithPreview($user->id, 'ics-pdf', 0, $card->id);

    DB::table('wizard_progress')
        ->where('user_id', $user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode([
            'card_import_run_ids' => [$healthyCard, $partRead, $unnamedAccount, $everyRowFailed, $expired->id, $emptyFile],
        ])]);
    DB::table('wizard_progress')
        ->where('user_id', $user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $healthyBank])]);

    return [
        'healthy' => [$healthyCard, $healthyBank],
        'refused' => [$partRead, $unnamedAccount, $everyRowFailed, $expired->id],
        'empty' => $emptyFile,
    ];
}

// The denominator. Without it a guard that seeded nothing refusable, or that
// stopped covering a refusal added later, would pass by finding nothing.
it('seeds a run for every refusal the confirm can raise', function (): void {
    $staged = stagedBatchCoveringEveryRefusal($this->user);

    /** @var PreviewCache $cache */
    $cache = $this->app->make(PreviewCache::class);

    $seeded = [];
    foreach ($staged['refused'] as $runId) {
        $refusal = $cache->head($runId)?->confirmRefusal();
        if ($refusal !== null) {
            $seeded[] = $refusal;
        }
    }

    expect($seeded)->toEqualCanonicalizing(ConfirmRefusal::cases())
        ->and($cache->head($staged['refused'][3]))->toBeNull();
});

it('offers only the runs the confirm will take, and still reads ready', function (): void {
    $staged = stagedBatchCoveringEveryRefusal($this->user);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build(
        [...$staged['healthy'], ...$staged['refused'], $staged['empty']],
        $this->user,
    );

    $cards = array_values(array_filter(
        $batch->sections,
        static fn ($section): bool => $section->sourceFormat === 'ics-pdf',
    ));

    expect($cards)->toHaveCount(1)
        ->and($cards[0]->status)->toBe(PreviewSectionStatus::Ready)
        ->and($cards[0]->importRunIds)->toBe([$staged['healthy'][0]])
        ->and($cards[0]->leftOutRunCount)->toBe(4)
        ->and($cards[0]->error)->not->toBeNull();
});

it('commits the statements staged beside every run the confirm refuses', function (): void {
    $staged = stagedBatchCoveringEveryRefusal($this->user);

    $component = Livewire::test(FirstImportStep::class)->call('commitEverything');

    expect($component->get('commitError'))->toBe('');
    $component->assertDispatched('wizard.step.completed');

    foreach ($staged['healthy'] as $runId) {
        expect(DB::table('import_runs')->where('id', $runId)->value('status'))->toBe('confirmed');
    }
});

// Not committed and not discarded: they stay previewed, which is what lets the
// reader upload the file again rather than find it silently gone.
it('leaves every refused run previewed rather than confirming or dropping it', function (): void {
    $staged = stagedBatchCoveringEveryRefusal($this->user);

    Livewire::test(FirstImportStep::class)->call('commitEverything');

    foreach ([...$staged['refused'], $staged['empty']] as $runId) {
        expect(DB::table('import_runs')->where('id', $runId)->value('status'))->toBe('previewed');
    }
});

// A statement with no rows in it is not a statement that was left out, and
// telling the reader it needs re-uploading sends them after a file that is
// exactly as complete as it will ever be.
it('reads an empty statement as empty rather than as a file it left out', function (): void {
    /** @var Account $card */
    $card = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICSCARD',
        'default_currency' => 'EUR',
    ]);

    $emptyRunId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'ics-pdf', 0, $card->id);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);

    $batch = $query->build([$emptyRunId], $this->user);

    expect($batch->sections)->toHaveCount(1)
        ->and($batch->sections[0]->status)->toBe(PreviewSectionStatus::Empty)
        ->and($batch->sections[0]->leftOutRunCount)->toBe(0);
});
