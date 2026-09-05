<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\FetchStop;

// 193,797 rows from 193,797 round trips in ten seconds, still going: a provider
// that never stops handing back a continuation key spins this walk until
// something unrelated falls over. The walk has to stop on its own and say why,
// because a truncated window that reports success is a silent data loss.

/**
 * A provider that always has one more page. `$keys` decides whether the cursor
 * it hands back is a fresh one or the same one over and over.
 */
function pwsFixtureClient(int $rowsPerPage, bool $freshKeys, int $abortAfter = 5000): EnableBankingHttpClient
{
    return new class($rowsPerPage, $freshKeys, $abortAfter) extends EnableBankingHttpClient
    {
        public int $calls = 0;

        public function __construct(
            private readonly int $rowsPerPage,
            private readonly bool $freshKeys,
            private readonly int $abortAfter,
        ) {}

        public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
        {
            return ['account_id' => ['iban' => 'NL01ASNB0000000001']];
        }

        public function transactions(
            OpenBankingCredentials $credentials,
            string $uid,
            FetchWindow $window,
            ?string $continuationKey = null,
        ): array {
            $this->calls++;
            if ($this->calls > $this->abortAfter) {
                throw new RuntimeException('The walk never stopped: '.$this->calls.' round trips and counting.');
            }

            $rows = [];
            for ($i = 0; $i < $this->rowsPerPage; $i++) {
                $rows[] = [
                    'entry_reference' => 'pws-'.$this->calls.'-'.$i,
                    'booking_date' => '2026-02-10',
                    'value_date' => '2026-02-10',
                    'status' => 'BOOK',
                    'credit_debit_indicator' => 'DBIT',
                    'transaction_amount' => ['currency' => 'EUR', 'amount' => '1.00'],
                    'creditor' => ['name' => 'Endless Merchant'],
                    'creditor_account' => ['iban' => 'NL91ABNA0417164300'],
                    'remittance_information' => ['endless'],
                ];
            }

            return [
                'transactions' => $rows,
                'continuation_key' => $this->freshKeys ? 'page-'.$this->calls : 'stuck',
            ];
        }
    };
}

function pwsCredentials(): OpenBankingCredentials
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

/**
 * @return array{walk: FetchWalk, rows: int, calls: int}
 */
function pwsWalk(EnableBankingHttpClient $client): array
{
    $adapter = new EnableBankingSourceAdapter($client);
    $generator = $adapter->fetch('acc-uid-123', new FetchWindow(
        dateFrom: CarbonImmutable::parse('2026-02-01'),
        dateTo: CarbonImmutable::parse('2026-02-28'),
    ), pwsCredentials());

    $rows = iterator_to_array($generator);

    /** @phpstan-ignore-next-line property.notFound — the fixture counter lives on the anonymous subclass */
    $calls = $client->calls;

    return ['walk' => $generator->getReturn(), 'rows' => count($rows), 'calls' => $calls];
}

it('stops on the page cap and names it, rather than following a provider that always has one more page', function (): void {
    $result = pwsWalk(pwsFixtureClient(rowsPerPage: 1, freshKeys: true));

    expect($result['walk']->stop)->toBe(FetchStop::PageCap);
    expect($result['walk']->isComplete())->toBeFalse();
    expect($result['walk']->pages)->toBe(100);
    expect($result['calls'])->toBe(100);
    expect($result['rows'])->toBe(100);
});

it('stops on the row cap when the pages are large rather than many', function (): void {
    $result = pwsWalk(pwsFixtureClient(rowsPerPage: 500, freshKeys: true));

    expect($result['walk']->stop)->toBe(FetchStop::RowCap);
    expect($result['walk']->rows)->toBe(25000);
    expect($result['calls'])->toBe(50);
});

// A cursor the provider has already served is the unambiguous no-progress
// signal, and it is worth catching before the caps: it stops on the second page
// instead of the hundredth.
it('stops the moment a continuation key repeats', function (): void {
    $result = pwsWalk(pwsFixtureClient(rowsPerPage: 3, freshKeys: false));

    expect($result['walk']->stop)->toBe(FetchStop::RepeatedCursor);
    expect($result['calls'])->toBe(2);
});

it('reports a walk that reached the end of the pages as complete', function (): void {
    $client = new class extends EnableBankingHttpClient
    {
        public int $calls = 0;

        public function __construct() {}

        public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
        {
            return ['account_id' => ['iban' => 'NL01ASNB0000000001']];
        }

        public function transactions(
            OpenBankingCredentials $credentials,
            string $uid,
            FetchWindow $window,
            ?string $continuationKey = null,
        ): array {
            $this->calls++;

            return ['transactions' => []];
        }
    };

    $result = pwsWalk($client);

    expect($result['walk']->isComplete())->toBeTrue();
    expect($result['walk']->stop)->toBe(FetchStop::Exhausted);
});
