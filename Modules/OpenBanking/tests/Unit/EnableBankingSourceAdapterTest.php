<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;

// EB rows must map onto SourceTransactionDto with the exact field choices
// Camt053Adapter uses, so both hash to one FingerprintComposer fingerprint.

/**
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

        /** @var list<OpenBankingCredentials> */
        public array $recordedCredentials = [];

        /**
         * @param  array<string, mixed>  $transactionsResponse
         * @param  array<string, mixed>  $accountDetailsResponse
         */
        public function __construct(
            private readonly array $transactionsResponse,
            private readonly array $accountDetailsResponse,
        ) {}

        public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
        {
            $this->recordedUids[] = $uid;
            $this->recordedCredentials[] = $credentials;

            return $this->accountDetailsResponse;
        }

        public function transactions(
            OpenBankingCredentials $credentials,
            string $uid,
            FetchWindow $window,
            ?string $continuationKey = null,
        ): array {
            $this->recordedUids[] = $uid;
            $this->recordedCredentials[] = $credentials;

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

// A yen has no minor unit. Parsing its amount at the repo-wide hundred reads
// every JPY row as a hundred times the figure the bank sent, and accepts a
// fractional yen the currency cannot express.
it('scales a booked amount by the row\'s OWN currency, not a fixed hundred', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::jpyTransactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    expect($rows)->toHaveCount(1);
    expect($rows[0]->currency)->toBe('JPY');
    expect($rows[0]->counterpartyName)->toBe('Yodobashi Camera');
    expect($rows[0]->amountMinor)->toBe(-980000);
});

// The same argument that fixes the scale restores the refusal: without it the
// over-precise figure is silently rounded into a yen amount nobody sent.
it('skips a booked row whose amount has more precision than its currency can hold', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::jpyTransactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    $names = array_map(static fn ($row): ?string => $row->counterpartyName, $rows);
    expect($names)->not->toContain('Impossible Yen Merchant');
});

it('maps bookedAt and postedAt to the SAME midnight-zeroed booking_date, never value_date', function (): void {
    // Fixture rows carry equal booking_date/value_date, which would mask a
    // mistaken substitution of either field; these two diverge so it cannot.
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

    // DBIT: the counterparty is the CREDITOR, matching Camt053Adapter's
    // direction rule and the CAMT.053 fixture entry for the same transaction.
    expect($rows[0]->amountMinor)->toBe(-399);
    expect($rows[0]->currency)->toBe('EUR');
    expect($rows[0]->counterpartyName)->toBe('Albert Heijn');
    expect($rows[0]->counterpartyIban)->toBe('NL67BANK0000000019');
    expect($rows[0]->sourceRef)->toBeNull();

    // CRDT: the counterparty is the DEBTOR.
    expect($rows[1]->amountMinor)->toBe(1167);
    expect($rows[1]->counterpartyName)->toBe('Coolblue 2');
    expect($rows[1]->counterpartyIban)->toBe('NL89BANK0000000011');

    // Normalising through the same FingerprintComposer::normalize() the other
    // adapters meet in NormalizeStage proves no second normalizer crept in.
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

// The client holds no credential of its own, so the ones the caller loaded for
// this reader and this bank have to travel with every call the walk makes --
// alongside the account uid, which is likewise never hardcoded here.
it('threads the same account uid and the caller\'s credentials to accountDetails() and transactions()', function (): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);
    $credentials = ebFixtureCredentials();

    iterator_to_array($adapter->fetch('sns-acc-uid-999', $this->window, $credentials));

    expect($client->recordedUids)->toBe(['sns-acc-uid-999', 'sns-acc-uid-999']);
    expect($client->recordedCredentials)->toBe([$credentials, $credentials]);
});

it('produces fingerprint parity with the overlapping ASN CAMT.053 fixture rows', function (): void {
    // The hash tuple's EB fields must be byte-for-byte the committed CAMT.053
    // fixture's. The XML is read directly to avoid a genkgo/camt dependency.
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

    // FingerprintComposer::compose()'s exact tuple string, so the EB fields are
    // shown to agree before CanonicalTransaction or NormalizeStage touch them.
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

it('throws rather than silently deriving a fingerprinted date from the wall clock when booking_date is missing', function (): void {
    // CarbonImmutable::parse('') does not throw, it resolves to "now" — which
    // would reach the fingerprinted bookedAt/postedAt. Refuse the row instead.
    $transactions = [
        'transactions' => [[
            'entry_reference' => 'REF-MISSING-DATE',
            'transaction_id' => 'TXN-MISSING-DATE',
            'status' => 'BOOK',
            'booking_date' => '',
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

    expect(fn () => iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials())))
        ->toThrow(RuntimeException::class, 'booking_date');
});

it('falls back value_date to booking_date rather than the wall clock when value_date is missing', function (): void {
    $transactions = [
        'transactions' => [[
            'entry_reference' => 'REF-MISSING-VALUE-DATE',
            'transaction_id' => 'TXN-MISSING-VALUE-DATE',
            'status' => 'BOOK',
            'booking_date' => '2026-03-10',
            'value_date' => '',
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
    expect($rows[0]->valueDate->toDateTimeString())->toBe('2026-03-10 00:00:00');
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

        public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
        {
            return $this->accountDetailsResponse;
        }

        public function transactions(
            OpenBankingCredentials $credentials,
            string $uid,
            FetchWindow $window,
            ?string $continuationKey = null,
        ): array {
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

// Every counterparty direction is judged against the own IBAN, so a fetch that
// cannot establish it must refuse rather than mislabel the whole window.
it('reads the own IBAN from account_id before falling back to the top level', function (array $details, string $expected): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $details);
    $adapter = new EnableBankingSourceAdapter($client);

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]->ownIban)->toBe($expected);
})->with([
    'nested under account_id' => [['uid' => 'acc-uid-123', 'account_id' => ['iban' => 'NL01ASNB0000000001']], 'NL01ASNB0000000001'],
    'only at the top level' => [['uid' => 'acc-uid-123', 'iban' => 'NL02ASNB0000000002'], 'NL02ASNB0000000002'],
    'account_id present but carrying no iban' => [['uid' => 'acc-uid-123', 'account_id' => ['other' => 'x'], 'iban' => 'NL03ASNB0000000003'], 'NL03ASNB0000000003'],
]);

it('refuses the whole fetch when no own IBAN can be resolved', function (array $details): void {
    $client = ebFixtureHttpClient(EnableBankingFixtures::transactions(), $details);
    $adapter = new EnableBankingSourceAdapter($client);

    expect(fn () => iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials())))
        ->toThrow(EnableBankingApiException::class, 'IBAN');
})->with([
    'nothing at all' => [['uid' => 'acc-uid-123']],
    'account_id without an iban' => [['uid' => 'acc-uid-123', 'account_id' => ['other' => 'x']]],
    'an empty iban string' => [['uid' => 'acc-uid-123', 'account_id' => ['iban' => ''], 'iban' => '']],
    'a non-string iban' => [['uid' => 'acc-uid-123', 'iban' => 12345]],
]);

// The import is a generator feeding a ledger write, so aborting on one
// unparseable amount would leave a partial import with no sign of where.
it('skips a booked row whose money will not parse and keeps the rest', function (array $badAmount): void {
    $transactions = EnableBankingFixtures::transactions();
    $rows = $transactions['transactions'];
    $poisoned = array_merge($rows[0], $badAmount);
    $transactions['transactions'] = array_merge([$poisoned], array_slice($rows, 1));

    $client = ebFixtureHttpClient($transactions, $this->accountDetailsResponse);
    $adapter = new EnableBankingSourceAdapter($client);

    $result = iterator_to_array($adapter->fetch('acc-uid-123', $this->window, ebFixtureCredentials()));

    // The fixture carries 3 booked rows; poisoning one leaves the other two.
    expect($result)->toHaveCount(2);
})->with([
    'unknown currency' => [['transaction_amount' => ['amount' => '10.00', 'currency' => 'ZZZ']]],
    'non-numeric amount' => [['transaction_amount' => ['amount' => 'not-a-number', 'currency' => 'EUR']]],
    'more precision than the currency allows' => [['transaction_amount' => ['amount' => '10.00123', 'currency' => 'EUR']]],
]);
