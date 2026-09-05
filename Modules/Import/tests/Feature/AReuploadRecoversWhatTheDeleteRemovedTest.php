<?php

declare(strict_types=1);

use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\AnchorsStartingBalanceFromStatements;
use Modules\Sync\Public\Services\DependentRowCascade;

// The gesture the recovery exists for: the reader deletes a derived row, then
// uploads the statement it came from again. Same bytes, so RunImport's SHA-256
// short-circuit answers -- and ConfirmImport, which holds the promotion, is
// never reached down that path.
function uploadTheSameStatementAgain(User $user): ImportPreviewResult
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    return $importer->runFromUpload(
        base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'),
        'ics-pdf',
        $user,
        'statement.pdf',
    );
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $this->firstRun = $importer->runAndConfirm(
        base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'),
        'ics-pdf',
        $this->fixtureUser,
    );
});

/**
 * @return list<array{string, string, int}> every card statement the user holds,
 *                                          as period and amount rather than id: the recovered row is a new
 *                                          insert, so the id is the one thing that cannot come back
 */
function cardStatementsHeldBy(User $user): array
{
    $held = CardStatement::query()
        ->where('user_id', $user->id)
        ->orderBy('period_start')
        ->orderBy('period_end')
        ->get()
        ->map(static fn (CardStatement $statement): array => [
            (string) $statement->period_start?->format('Y-m-d'),
            (string) $statement->period_end?->format('Y-m-d'),
            (int) $statement->total_amount_minor,
        ])
        ->all();

    return array_values($held);
}

it('recovers a card statement the reader deleted, on a re-upload of the same bytes', function (): void {
    $before = cardStatementsHeldBy($this->fixtureUser);
    expect($before)->not->toBe([]);

    $ids = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
    $this->app->make(DependentRowCascade::class)
        ->deleteAll('card_statements', $ids, (int) $this->fixtureUser->id);
    CardStatement::query()->where('user_id', $this->fixtureUser->id)->delete();

    expect(cardStatementsHeldBy($this->fixtureUser))->toBe([]);

    uploadTheSameStatementAgain($this->fixtureUser);

    expect(cardStatementsHeldBy($this->fixtureUser))->toBe($before);
});

it('runs both post-commit promotions on the content-hash short-circuit', function (): void {
    $upserter = new class($this->app->make(UpsertsCardStatements::class)) implements UpsertsCardStatements
    {
        public int $forRun = 0;

        public function __construct(private readonly UpsertsCardStatements $inner) {}

        public function upsertForImportRun(int $importRunId, User $user): int
        {
            $this->forRun++;

            return $this->inner->upsertForImportRun($importRunId, $user);
        }

        public function upsertForUser(User $user): int
        {
            return $this->inner->upsertForUser($user);
        }
    };

    $anchor = new class($this->app->make(AnchorsStartingBalanceFromStatements::class)) implements AnchorsStartingBalanceFromStatements
    {
        public int $forUser = 0;

        public function __construct(private readonly AnchorsStartingBalanceFromStatements $inner) {}

        public function anchorForUser(User $user): int
        {
            $this->forUser++;

            return $this->inner->anchorForUser($user);
        }
    };

    $this->app->instance(UpsertsCardStatements::class, $upserter);
    $this->app->instance(AnchorsStartingBalanceFromStatements::class, $anchor);

    uploadTheSameStatementAgain($this->fixtureUser);

    expect($upserter->forRun)->toBe(1)
        ->and($anchor->forUser)->toBe(1);
});

// The recovery had to be threaded through the short-circuit, not around it:
// what the caller gets back is still the empty preview, and the parse the
// content hash saved is still saved.
it('still short-circuits to an empty preview and imports nothing twice', function (): void {
    $runsBefore = ImportRun::query()->count();
    $transactionsBefore = Transaction::query()->count();

    $preview = uploadTheSameStatementAgain($this->fixtureUser);

    expect($preview->importRunId)->toBe($this->firstRun->importRunId)
        ->and($preview->rows)->toBe([])
        ->and($preview->accountsToName)->toBe([])
        ->and(ImportRun::query()->count())->toBe($runsBefore)
        ->and(Transaction::query()->count())->toBe($transactionsBefore);
});
