<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;

uses(RefreshDatabase::class);

/*
 * LOAD-BEARING contract (Req 5 — the single most important test in
 * Phase 19): an Enable Banking fetch whose window overlaps a prior ASN
 * CAMT.053 file import commits ZERO net-new duplicate rows for the
 * shared transactions, while a genuinely-new EB-only row DOES commit —
 * proving the test is not trivially passing by dropping everything.
 *
 * Role-matches `Modules\Receipts\tests\Contracts\FingerprintParityTest`
 * (cross-format dedup is load-bearing there too) but exercises the
 * REAL end-to-end pipeline both directions: `runFromUpload` +
 * `ConfirmImport` for the CAMT.053 twin, `runFromRemoteFetch` +
 * `ConfirmImport` for the EB fetch — through the SAME
 * `RunsImports`/`ConfirmsImports` Public entry points production code
 * uses, no shortcuts, no hand-built canonical rows.
 *
 * `enable-banking-transactions.json` (19-01 fixture, paired with the
 * committed ASN CAMT.053 fixture) carries exactly the rows this test
 * needs:
 *  - EB-TXN-0001 (Albert Heijn, DBIT, -3.99 EUR) and EB-TXN-0002
 *    (Coolblue 2, CRDT, +11.67 EUR) exist verbatim in the CAMT.053
 *    fixture (entry_reference 20260202-898406 / 20260205-2850362) — the
 *    OVERLAPPING pair.
 *  - EB-TXN-0003 (status PDNG) is filtered by the adapter's booked-only
 *    rule before it ever reaches the pipeline — never a candidate for
 *    either duplicate or new disposition.
 *  - EB-TXN-0004 (Netflix, BOOK, DBIT, -12.99 EUR) has NO twin in the
 *    CAMT.053 fixture — the EB-ONLY row that must commit as new.
 */

final class ParityStubHttpClient extends EnableBankingHttpClient
{
    /**
     * @param  array<string, mixed>  $transactionsResponse
     * @param  array<string, mixed>  $accountDetailsResponse
     */
    public function __construct(
        private readonly array $transactionsResponse,
        private readonly array $accountDetailsResponse,
    ) {}

    public function accountDetails(string $uid): array
    {
        return $this->accountDetailsResponse;
    }

    public function transactions(string $uid, FetchWindow $window, ?string $continuationKey = null): array
    {
        return $this->transactionsResponse;
    }
}

it('an EB fetch overlapping a prior CAMT.053 import commits ZERO net-new rows for the shared transactions, and commits the EB-only row as new', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);

    // 1. Import the paired ASN CAMT.053 fixture first — the "prior file
    // import" side of the overlap.
    $camtResult = $importer->runAndConfirm(
        EnableBankingFixtures::overlappingCamt053FixturePath(),
        'camt053',
        $user,
    );
    expect($camtResult->inserted)->toBeGreaterThan(0);
    expect($camtResult->errors)->toBe(0);

    $baselineCount = Transaction::query()->count();
    expect($baselineCount)->toBe($camtResult->inserted);

    // 2. Fetch the overlapping EB window. The adapter is fed the
    // synthetic EB fixture through a stub HTTP client (no live API
    // call, per this test's own contract); ownIban resolves to the SAME
    // account the CAMT import used, so both paths converge on the SAME
    // account_id in the fingerprint tuple.
    $accountDetailsResponse = [
        'uid' => 'acc-uid-parity-1',
        'account_id' => ['iban' => 'NL57ASNB0123456789'],
    ];
    $client = new ParityStubHttpClient(EnableBankingFixtures::transactions(), $accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $window = new FetchWindow(
        dateFrom: CarbonImmutable::parse('2026-02-01'),
        dateTo: CarbonImmutable::parse('2026-02-10'),
    );
    $credentials = new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: 'unused-in-this-test',
        sessionId: 'fixture-session-id',
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: 'ASNBNL21',
    );

    $generator = $adapter->fetch('acc-uid-parity-1', $window, $credentials);
    $idempotencyKey = hash('sha256', 'open-banking:ASNBNL21:acc-uid-parity-1:2026-02-01:2026-02-10');

    $preview = $importer->runFromRemoteFetch($generator, 'enable-banking', $user, $idempotencyKey);

    // Booked-only filter already dropped EB-TXN-0003 (PDNG) inside the
    // adapter — the preview only ever sees 3 candidate rows: 2
    // overlapping + 1 EB-only.
    expect($preview->rows)->toHaveCount(3);

    /** @var array<string, string> $statusesByCounterparty */
    $statusesByCounterparty = [];
    foreach ($preview->rows as $row) {
        $statusesByCounterparty[(string) $row->counterpartyName] = $row->status;
    }
    expect($statusesByCounterparty['Albert Heijn'] ?? null)->toBe('duplicate');
    expect($statusesByCounterparty['Coolblue 2'] ?? null)->toBe('duplicate');
    expect($statusesByCounterparty['Netflix'] ?? null)->toBe('new');

    // 3. Confirm — the overlapping rows must commit ZERO net-new rows;
    // the EB-only row must commit as exactly one new row.
    $ebResult = ($confirmer)($preview->importRunId, $user);

    expect($ebResult->inserted)->toBe(1);
    expect($ebResult->duplicates)->toBe(2);
    expect($ebResult->errors)->toBe(0);

    // 4. The falsifiable proof: total ledger row count only grew by
    // exactly the one genuinely-new row — zero net-new duplicates for
    // the overlapping pair.
    expect(Transaction::query()->count())->toBe($baselineCount + 1);
    expect(Transaction::query()->where('source_format', 'enable-banking')->count())->toBe(1);

    /** @var Transaction $netflixRow */
    $netflixRow = Transaction::query()->where('source_format', 'enable-banking')->firstOrFail();
    expect($netflixRow->counterparty_name)->toBe('Netflix');
    expect($netflixRow->amount_minor)->toBe(-1299);
    expect($netflixRow->currency)->toBe('EUR');

    // No enable-banking-sourced rows exist for the two overlapping
    // counterparties beyond the ones the CAMT.053 import already
    // landed — the overlap produced zero net-new rows, not merely "a
    // low count".
    expect(
        Transaction::query()
            ->where('source_format', 'enable-banking')
            ->where('counterparty_name', 'Albert Heijn')
            ->count()
    )->toBe(0);
    expect(
        Transaction::query()
            ->where('source_format', 'enable-banking')
            ->where('counterparty_name', 'Coolblue 2')
            ->count()
    )->toBe(0);
});
