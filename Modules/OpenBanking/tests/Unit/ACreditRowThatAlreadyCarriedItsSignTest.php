<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

// credit_debit_indicator carries the sign and transaction_amount.amount is meant
// to be a magnitude -- but it arrives as the aggregator's own string and
// MoneyInput::tryToMinor() honours a leading '-'. The debit branch normalised
// only where the parse came back positive and the credit branch normalised
// nothing, so a feed that signs its figures put a payment received on the wrong
// side of zero, and the sign is inside the fingerprint tuple.

/**
 * @param  list<array<string, mixed>>  $rows
 */
function signedRowsClient(array $rows): EnableBankingHttpClient
{
    return new class($rows) extends EnableBankingHttpClient
    {
        /** @param list<array<string, mixed>> $rows */
        public function __construct(private readonly array $rows) {}

        /** @return array<string, mixed> */
        public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
        {
            return ['uid' => $uid, 'account_id' => ['iban' => 'NL01ASNB0000000001']];
        }

        /** @return array<string, mixed> */
        public function transactions(
            OpenBankingCredentials $credentials,
            string $uid,
            FetchWindow $window,
            ?string $continuationKey = null,
        ): array {
            return ['transactions' => $this->rows, 'continuation_key' => null];
        }
    };
}

/**
 * @return array<string, mixed>
 */
function signedRow(string $indicator, string $amount): array
{
    return [
        'entry_reference' => '20260202-'.$indicator.'-'.$amount,
        'transaction_id' => 'EB-SIGNED-'.$indicator,
        'status' => 'BOOK',
        'booking_date' => '2026-02-02',
        'value_date' => '2026-02-02',
        'transaction_amount' => ['amount' => $amount, 'currency' => 'EUR'],
        'credit_debit_indicator' => $indicator,
        'creditor' => ['name' => 'Albert Heijn'],
        'creditor_account' => ['iban' => 'NL67BANK0000000019'],
        'debtor' => ['name' => 'Werkgever BV'],
        'debtor_account' => ['iban' => 'NL67BANK0000000021'],
        'remittance_information' => ['Betaling'],
    ];
}

function signedRowsCredentials(): OpenBankingCredentials
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

it('signs every row off its indicator whatever sign the figure arrived with', function (): void {
    $client = signedRowsClient([
        signedRow('CRDT', '-25.00'),
        signedRow('CRDT', '25.00'),
        signedRow('DBIT', '-25.00'),
        signedRow('DBIT', '25.00'),
    ]);

    $adapter = new EnableBankingSourceAdapter($client);
    $window = new FetchWindow(
        dateFrom: CarbonImmutable::parse('2026-02-01'),
        dateTo: CarbonImmutable::parse('2026-02-28'),
    );

    $rows = iterator_to_array($adapter->fetch('acc-uid-123', $window, signedRowsCredentials()), false);

    expect($rows)->toHaveCount(4);

    // The first row is the defect: a CRDT figure the feed had already signed
    // landed at -2500, which NormalizeStage then types as an expense.
    expect($rows[0]->amountMinor)->toBe(2500);
    expect($rows[1]->amountMinor)->toBe(2500);
    expect($rows[2]->amountMinor)->toBe(-2500);
    expect($rows[3]->amountMinor)->toBe(-2500);
});
