<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\Services\CardStatementUpserter;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/*
 * Feature-level wiring coverage for ConfirmImport's new
 * UpsertsCardStatements call. Three invariants:
 *
 *   1. A real ICS PDF import → ConfirmImport → produces a fresh
 *      card_statements row for the imported period.
 *   2. Re-confirming an already-confirmed run does NOT duplicate
 *      card_statements rows.
 *   3. A zero-row ConfirmImport (no inserts AND no enrichments) does
 *      NOT call the upserter — proven via a counting spy bound on the
 *      Public contract.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $this->importer = $importer;
    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);
    $this->confirmer = $confirmer;
    $this->tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

it('ConfirmImport promotes new ICS statement_summaries into card_statements before chain dispatch', function (): void {
    $countBefore = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->count();

    $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    $countAfter = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->count();
    expect($countAfter)->toBeGreaterThan($countBefore);

    // The new row belongs to the user's ics_card account.
    /** @var Account $icsAccount */
    $icsAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('kind', 'ics_card')
        ->firstOrFail();
    /** @var CardStatement $row */
    $row = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('account_id', $icsAccount->id)
        ->latest('id')
        ->firstOrFail();
    expect($row->state)->toBe('open');
    expect($row->total_amount_minor)->toBeLessThanOrEqual(0);
    expect($row->open_balance_minor)->toBe(abs($row->total_amount_minor));
});

it('re-confirming the same import_run does NOT duplicate card_statements rows', function (): void {
    $first = $this->importer->runAndConfirm($this->tinyPdf, 'ics-pdf', $this->fixtureUser);

    $countAfterFirst = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->count();

    // Idempotent re-confirm — short-circuits via the
    // status='confirmed' path AND would be a no-op even if it did not.
    ($this->confirmer)($first->importRunId, $this->fixtureUser);

    $countAfterSecond = CardStatement::query()
        ->where('user_id', $this->fixtureUser->id)
        ->count();
    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('re-confirming an already-confirmed import_run short-circuits via the status=confirmed path and never calls the upserter', function (): void {
    // The action's first early-return runs when ImportRun.status is
    // already `confirmed`; it returns an idempotent zero-action
    // result and skips the post-commit block entirely. This test
    // pins that invariant via a counting spy on the upserter — the
    // spy's call count must stay at zero across the re-confirm.
    //
    // NOTE: a zero-inserts / zero-enrichments confirm whose
    // ImportRun is NOT yet `confirmed` DOES call the upserter (the
    // upserter is idempotent and Stage A of the post-commit block
    // is no longer gated on the recorder counts — that recovery
    // path lets a re-import refill a manually-deleted
    // card_statements row). The `status=confirmed` short-circuit is
    // the only path that bypasses Stage A.
    $spy = new class($this->app->make(CardStatementUpserter::class)) implements UpsertsCardStatements
    {
        public int $callCount = 0;

        public function __construct(private readonly CardStatementUpserter $inner) {}

        public function upsertForImportRun(int $importRunId, User $user): int
        {
            $this->callCount++;

            return $this->inner->upsertForImportRun($importRunId, $user);
        }
    };
    $this->app->instance(UpsertsCardStatements::class, $spy);

    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/zero.pdf',
        'sha256' => str_repeat('z', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'enriched_count' => 0,
        'error_count' => 0,
    ]);

    ($this->confirmer)($run->id, $this->fixtureUser);

    expect($spy->callCount)->toBe(0);
    // Sanity — no card_statements rows landed via the re-confirm.
    expect(CardStatement::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});
