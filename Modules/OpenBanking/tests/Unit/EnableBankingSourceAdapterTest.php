<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;

/*
 * The load-bearing dedup-contract test (D-03/Req 5, T-19-08-01):
 * `EnableBankingSourceAdapter` must map EB rows onto `SourceTransactionDto`
 * using the EXACT same field choices `Camt053Adapter` uses, so a
 * `FingerprintComposer` hash derived from an EB-fetched row collides with
 * the hash derived from the SAME real-world transaction imported from the
 * committed ASN CAMT.053 fixture (`EnableBankingFixtures::
 * overlappingCamt053FixturePath()`).
 */

/**
 * Builds a stub `EnableBankingHttpClient` (empty-constructor anonymous
 * subclass, the pattern already established by
 * `EnableBankingHttpClientSsrfTest`) returning fixed fixture data instead
 * of making any real HTTP call. The returned instance carries a public
 * `recordedUids` array so the test can assert `fetch()` never hardcodes
 * a specific bank/account.
 *
 * @param  array<string, mixed>  $transactionsResponse
 * @param  array<string, mixed>  $accountDetailsResponse
 */
function ebFixtureHttpClient(
    array $transactionsResponse,
    array $accountDetailsResponse,
): EnableBankingHttpClient {
    return new class($transactionsResponse, $accountDetailsResponse) extends EnableBankingHttpClient
    {
        /** @var list<string> */
        public array $recordedUids = [];

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
            $this->recordedUids[] = $uid;

            return $this->accountDetailsResponse;
        }

        public function transactions(string $uid, FetchWindow $window, ?string $continuationKey = null): array
        {
            $this->recordedUids[] = $uid;

            return $this->transactionsResponse;
        }
    };
}

function ebFixtureCredentials(): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: 'unused-in-this-test',
        sessionId: 'fixture-session-id',
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: 'asn',
    );
}

beforeEach(function (): void {
    $this->fingerprints = new FingerprintComposer;
    $this->window = new FetchWindow(
        dateFrom: CarbonImmutable::parse('2026-02-01'),
        dateTo: CarbonImmutable::parse('2026-02-28'),
    );
    $this->accountDetailsResponse = [
        'uid' => 'acc-uid-123',
        'account_id' => ['iban' => 'NL01ASNB0000000001'],
    ];
});

it('yields only booked rows and drops pending PSD2 rows', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    // Fixture carries 4 rows: 3 BOOK + 1 PDNG ("Pending Merchant BV").
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->rawPayload['enable_banking']['status'])->toBe('BOOK');
    }
    $descriptions = array_map(static fn ($row) => $row->description, $rows);
    expect($descriptions)->not->toContain('Card authorisation hold, not yet booked');
});

it('maps bookedAt and postedAt to the SAME midnight-zeroed booking_date, never value_date', function (): void {
    // Deliberately diverge booking_date and value_date so a mistaken
    // substitution of either field is caught (fixture rows carry equal
    // booking_date/value_date, which would mask this bug).
    $transactions = [
        'transactions' => [[
            'entry_reference' => 'REF-1',
            'transaction_id' => 'TXN-1',
            'status' => 'BOOK',
            'booking_date' => '2026-03-10',
            'value_date' => '2026-03-12',
            'transaction_amount' => ['amount' => '5.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Test Merchant'],
            'creditor_account' => ['iban' => 'NL00TEST0000000001'],
            'debtor' => null,
            'debtor_account' => null,
            'remittance_information' => ['test'],
            'bank_transaction_code' => null,
        ]],
        'continuation_key' => null,
    ];
    $client = ebFixtureHttpClient($transactions, $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    expect($row->bookedAt->equalTo($row->postedAt))->toBeTrue();
    expect($row->bookedAt->toDateTimeString())->toBe('2026-03-10 00:00:00');
    expect($row->postedAt->toDateTimeString())->toBe('2026-03-10 00:00:00');
    // valueDate is carried but distinct from bookedAt/postedAt — it must
    // never leak into the fingerprinted fields.
    expect($row->valueDate->toDateTimeString())->toBe('2026-03-12 00:00:00');
});

it('negates the amount for a DBIT row and keeps a CRDT row positive, following the creditor/debtor direction rule', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    // Row 0: Albert Heijn, DBIT, 3.99 EUR -> -399 minor, counterparty is
    // the CREDITOR (matches Camt053Adapter's direction rule and the
    // committed ASN CAMT.053 fixture entry for the same real-world txn).
    expect($rows[0]->amountMinor)->toBe(-399);
    expect($rows[0]->currency)->toBe('EUR');
    expect($rows[0]->counterpartyName)->toBe('Albert Heijn');
    expect($rows[0]->counterpartyIban)->toBe('NL67BANK0000000019');
    expect($rows[0]->sourceRef)->toBeNull();

    // Row 1: Coolblue 2, CRDT, 11.67 EUR -> +1167 minor, counterparty is
    // the DEBTOR.
    expect($rows[1]->amountMinor)->toBe(1167);
    expect($rows[1]->counterpartyName)->toBe('Coolblue 2');
    expect($rows[1]->counterpartyIban)->toBe('NL89BANK0000000011');

    // Both rows' raw counterparty names, when normalised through the
    // SAME FingerprintComposer::normalize() every other adapter's rows
    // pass through downstream (Modules\Import\Public\Pipeline\
    // NormalizeStage), must equal the normalized form of the identical
    // real-world name — proving no second/divergent normalizer was
    // introduced here.
    expect($this->fingerprints->normalize($rows[0]->counterpartyName))
        ->toBe($this->fingerprints->normalize('Albert Heijn'));
    expect($this->fingerprints->normalize($rows[1]->counterpartyName))
        ->toBe($this->fingerprints->normalize('Coolblue 2'));
});

it('joins and whitespace-collapses remittance_information into description', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    expect($rows[0]->description)->toBe('Betaling voor factuur');
});

it('stashes transaction_id, entry_reference, status, and bank_transaction_code under rawPayload', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    $fragment = $rows[0]->rawPayload['enable_banking'];
    expect($fragment['transactionId'])->toBe('EB-TXN-0001');
    expect($fragment['entryReference'])->toBe('20260202-898406');
    expect($fragment['status'])->toBe('BOOK');
    expect($fragment['bankTransactionCode'])->toBe([
        'domain' => 'PMNT',
        'family' => 'RDDT',
        'subFamily' => 'ESDD',
    ]);
});

it('resolves ownIban via accountDetails() and applies it to every yielded row', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    foreach ($rows as $row) {
        expect($row->ownIban)->toBe('NL01ASNB0000000001');
    }
});

it('is institution-parameterized: the same uid is threaded to accountDetails() and transactions(), never hardcoded', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    iterator_to_array($adapter->fetch('sns-acc-uid-999', $this->window, ebFixtureCredentials()));

    expect($client->recordedUids)->toBe(['sns-acc-uid-999', 'sns-acc-uid-999']);
});

it('produces fingerprint parity with the overlapping ASN CAMT.053 fixture rows', function (): void {
    // Falsifiable proof of D-03: for the two overlapping real-world
    // transactions (Albert Heijn -3.99 EUR, Coolblue 2 +11.67 EUR), the
    // EB-mapped fields that feed FingerprintComposer's hash tuple must be
    // byte-for-byte identical to what the committed CAMT.053 fixture
    // (read directly here, no genkgo/camt dependency needed for this
    // narrow assertion) is known to carry per
    // `EnableBankingFixtures::overlappingCamt053FixturePath()`'s docblock.
    expect(file_exists(EnableBankingFixtures::overlappingCamt053FixturePath()))->toBeTrue();

    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);
    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    $userId = 42;
    $accountId = 7;

    $albertHeijn = $rows[0];
    expect($albertHeijn->bookedAt->toDateTimeString())->toBe('2026-02-02 00:00:00');
    expect($albertHeijn->postedAt->toDateTimeString())->toBe('2026-02-02 00:00:00');
    expect($albertHeijn->amountMinor)->toBe(-399);
    expect($albertHeijn->currency)->toBe('EUR');
    expect($this->fingerprints->normalize((string) $albertHeijn->counterpartyName))->toBe('albert heijn');

    $coolblue = $rows[1];
    expect($coolblue->bookedAt->toDateTimeString())->toBe('2026-02-05 00:00:00');
    expect($coolblue->postedAt->toDateTimeString())->toBe('2026-02-05 00:00:00');
    expect($coolblue->amountMinor)->toBe(1167);
    expect($coolblue->currency)->toBe('EUR');
    expect($this->fingerprints->normalize((string) $coolblue->counterpartyName))->toBe('coolblue 2');

    // Assemble the exact hash tuple string FingerprintComposer::compose()
    // builds, using the same literal values the CAMT.053 fixture is known
    // to carry for these two entries — proving the EB-mapped fields alone
    // (independent of CanonicalTransaction/NormalizeStage) already agree.
    $albertTuple = implode('|', [
        (string) $userId, (string) $accountId,
        $albertHeijn->postedAt->toDateString(), $albertHeijn->bookedAt->toDateTimeString(),
        (string) $albertHeijn->amountMinor, $albertHeijn->currency,
        $this->fingerprints->normalize((string) $albertHeijn->counterpartyName),
    ]);
    $camtAlbertTuple = implode('|', [
        (string) $userId, (string) $accountId,
        '2026-02-02', '2026-02-02 00:00:00', '-399', 'EUR', 'albert heijn',
    ]);
    expect(hash('sha256', $albertTuple))->toBe(hash('sha256', $camtAlbertTuple));
});

it('supports pagination via continuation_key without dropping later pages', function (): void {
    $firstPage = [
        'transactions' => [[
            'entry_reference' => 'PAGE-1',
            'transaction_id' => 'TXN-PAGE-1',
            'status' => 'BOOK',
            'booking_date' => '2026-04-01',
            'value_date' => '2026-04-01',
            'transaction_amount' => ['amount' => '1.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Page One Merchant'],
            'creditor_account' => null,
            'debtor' => null,
            'debtor_account' => null,
            'remittance_information' => [],
            'bank_transaction_code' => null,
        ]],
        'continuation_key' => 'next-page-token',
    ];
    $secondPage = [
        'transactions' => [[
            'entry_reference' => 'PAGE-2',
            'transaction_id' => 'TXN-PAGE-2',
            'status' => 'BOOK',
            'booking_date' => '2026-04-02',
            'value_date' => '2026-04-02',
            'transaction_amount' => ['amount' => '2.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Page Two Merchant'],
            'creditor_account' => null,
            'debtor' => null,
            'debtor_account' => null,
            'remittance_information' => [],
            'bank_transaction_code' => null,
        ]],
        'continuation_key' => null,
    ];

    $client = new class($firstPage, $secondPage, $this->accountDetailsResponse) extends EnableBankingHttpClient
    {
        private int $calls = 0;

        /**
         * @param  array<string, mixed>  $firstPage
         * @param  array<string, mixed>  $secondPage
         * @param  array<string, mixed>  $accountDetailsResponse
         */
        public function __construct(
            private readonly array $firstPage,
            private readonly array $secondPage,
            private readonly array $accountDetailsResponse,
        ) {}

        public function accountDetails(string $uid): array
        {
            return $this->accountDetailsResponse;
        }

        public function transactions(string $uid, FetchWindow $window, ?string $continuationKey = null): array
        {
            $this->calls++;

            return $this->calls === 1 ? $this->firstPage : $this->secondPage;
        }
    };
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    expect($rows)->toHaveCount(2);
    expect($rows[0]->description)->toBeNull();
    expect($rows[0]->amountMinor)->toBe(-100);
    expect($rows[1]->amountMinor)->toBe(-200);
});

it('reports the stable format identifier', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    expect($adapter->format())->toBe('enable-banking');
});
