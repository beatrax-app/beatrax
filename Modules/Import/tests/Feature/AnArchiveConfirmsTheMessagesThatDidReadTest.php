<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Tests\Helpers\UploadIsolation;

// A statement is one continuous period, so a read that stops part-way through
// one is refused whole: filing 499 entries of 1200 ends the ledger mid-month
// with nothing saying so. An archive is not continuous. It is a concatenation
// of independent documents, and refusing all of it over one message that will
// not read throws away every receipt beside it to guard a gap that cannot
// exist. The two share ImportPipeline's file-level catch, and once the
// statement rule landed they shared its answer as well. The statement half of
// the pair stays pinned in ARunThatStoppedReadingIsNotImportableTest, which
// this must never start contradicting.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->actingAs($this->fixtureUser);

    // The wallet the archive's readable receipt lands in. Without it every
    // receipt row is an unknown-IBAN error and the run is refused for a reason
    // that has nothing to do with the message that would not read.
    ($this->app->make(EnsurePaypalAccountAction::class))($this->fixtureUser);

    // A real matcher through the app's own extension point rather than a
    // double: it claims one sender in the archive and raises on the body,
    // which is what an unreadable document looks like from the pipeline.
    $this->app->bind('tests.archive-matcher-that-raises', static fn (): SenderMatcher => new class implements SenderMatcher
    {
        public function key(): string
        {
            return 'test-raises';
        }

        public function priority(): int
        {
            return 500;
        }

        public function canHandle(InboxMessageDto $msg): bool
        {
            return $msg->senderEmail === 'notifications@netflix.com';
        }

        public function match(string $emlRaw): MatchOutcomeDto
        {
            throw new RuntimeException('This message could not be read.');
        }
    });
    $this->app->tag(['tests.archive-matcher-that-raises'], 'receipts.matcher');
});

// Three messages: a PayPal receipt that becomes a transaction, the one the
// matcher above raises on, and a sign-in notice that is filed and yields no
// row. Two of three is the shape of 399 of 400.
function previewOfTheArchiveWithAMessageThatWillNotRead(User $user): ImportPreviewResult
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    return $importer->runFromUpload(
        __DIR__.'/../../../Receipts/tests/fixtures/mbox/paypal-mixed.mbox',
        'mbox',
        $user,
        'paypal-mixed.mbox',
    );
}

it('reads the archive past the message that raised rather than calling the file unreadable', function (): void {
    $preview = previewOfTheArchiveWithAMessageThatWillNotRead($this->fixtureUser);

    expect($preview->fileFailureReason)->toBeNull()
        ->and($preview->errorRows())->toBe(1)
        ->and($preview->importableRows())->toBeGreaterThan(0);
});

it('confirms the archive and lands what the readable messages carried', function (): void {
    $preview = previewOfTheArchiveWithAMessageThatWillNotRead($this->fixtureUser);

    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);
    $result = ($confirmer)($preview->importRunId, $this->fixtureUser);

    expect($result->inserted)->toBeGreaterThan(0)
        ->and(ImportRun::query()->findOrFail($preview->importRunId)->status)->toBe(ImportRunStatus::Confirmed->value)
        ->and(Transaction::query()->where('import_run_id', $preview->importRunId)->count())->toBe($result->inserted);
});

// Every message is still filed, including the one no payment could be read out
// of: the archive is the reader's own mail, and losing one in silence is the
// failure this whole path exists to avoid.
it('tells the reader a message was skipped instead of dropping it in silence', function (): void {
    $preview = previewOfTheArchiveWithAMessageThatWillNotRead($this->fixtureUser);

    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(3);

    Livewire::actingAs($this->fixtureUser)
        ->test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertDontSee(Lang::get('import::preview.failed.truncated_heading'))
        ->assertSee(Lang::get('import::preview.failed.some_rows'))
        ->assertSee(Lang::get('import::preview.errors.message_unreadable'));
});
