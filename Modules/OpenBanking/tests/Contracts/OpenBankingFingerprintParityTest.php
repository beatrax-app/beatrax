<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;
use Modules\OpenBanking\Tests\Support\ParityStubHttpClient;

uses(RefreshDatabase::class);

// The fixtures make this falsifiable: two rows exist verbatim in both the EB
// JSON and the ASN CAMT.053 file (must dedup), one is PDNG (dropped), and one
// has no CAMT twin — so a run that dropped everything could not pass.

it('an EB fetch overlapping a prior CAMT.053 import commits ZERO net-new rows for the shared transactions, and commits the EB-only row as new', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);

    $camtResult = $importer->runAndConfirm(
        EnableBankingFixtures::overlappingCamt053FixturePath(),
        'camt053',
        $user,
    );
    expect($camtResult->inserted)->toBeGreaterThan(0);
    expect($camtResult->errors)->toBe(0);

    $baselineCount = Transaction::query()->count();
    expect($baselineCount)->toBe($camtResult->inserted);

    // ownIban must resolve to the SAME account the CAMT import used, or the
    // two paths never converge on one account_id in the fingerprint tuple.
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

    // Three, not four: the adapter's booked-only filter dropped the PDNG row
    // before the pipeline ever saw it.
    expect($preview->rows)->toHaveCount(3);

    /** @var array<string, PreviewRowStatus> $statusesByCounterparty */
    $statusesByCounterparty = [];
    foreach ($preview->rows as $row) {
        $statusesByCounterparty[(string) $row->counterpartyName] = $row->status;
    }
    expect($statusesByCounterparty['Albert Heijn'] ?? null)->toBe(PreviewRowStatus::Duplicate);
    expect($statusesByCounterparty['Coolblue 2'] ?? null)->toBe(PreviewRowStatus::Duplicate);
    expect($statusesByCounterparty['Netflix'] ?? null)->toBe(PreviewRowStatus::NewRow);

    $ebResult = ($confirmer)($preview->importRunId, $user);

    expect($ebResult->inserted)->toBe(1);
    expect($ebResult->duplicates)->toBe(2);
    expect($ebResult->errors)->toBe(0);

    expect(Transaction::query()->count())->toBe($baselineCount + 1);
    expect(Transaction::query()->where('source_format', 'enable-banking')->count())->toBe(1);

    /** @var Transaction $netflixRow */
    $netflixRow = Transaction::query()->where('source_format', 'enable-banking')->firstOrFail();
    expect($netflixRow->counterparty_name)->toBe('Netflix');
    expect($netflixRow->amount_minor)->toBe(-1299);
    expect($netflixRow->currency)->toBe('EUR');

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
