<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\FetchStop;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class EnableBankingSourceAdapter implements RemoteSourceAdapter
{
    // The widest window this connector ever asks for is the 90-day initial
    // lookback. 100 pages and 25,000 rows are an order of magnitude past what
    // any personal account books in 90 days, and they are what stops a
    // provider handing back a fresh continuation key forever.
    /**
     * @link ../../../../../.docs/features/open-banking/fetch-cursor.md#bounding-the-page-walk
     */
    private const int MAX_PAGES = 100;

    private const int MAX_ROWS = 25000;

    public function __construct(
        private EnableBankingHttpClient $client,
        private LoggerInterface $logger = new NullLogger,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    /**
     * @return Generator<int, SourceTransactionDto, mixed, FetchWalk>
     */
    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $ownIban = $this->resolveOwnIban($credentials, $accountUid);

        $rowIndex = 0;
        $scanned = 0;
        $pages = 0;
        $continuationKey = null;
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];

        while (true) {
            $response = $this->client->transactions($credentials, $accountUid, $window, $continuationKey);
            $pages++;
            $rows = EnableBankingTransactionData::collectionFromArray($this->transactionRows($response));

            foreach ($rows as $row) {
                $scanned++;

                if (! $row->isBooked()) {
                    continue;
                }

                $dto = $this->buildDto($row, $ownIban, $rowIndex);
                if ($dto === null) {
                    continue;
                }

                yield $dto;
                $rowIndex++;
            }

            $continuationKey = $this->continuationKeyFrom($response);
            if ($continuationKey === null) {
                return FetchWalk::exhausted($pages, $scanned);
            }

            $stop = $this->boundReached($pages, $scanned, $continuationKey, $seenKeys);
            if ($stop !== null) {
                $this->logger->warning('EnableBankingSourceAdapter: stopped walking pages before the bank ran out.', [
                    'stop' => $stop->value,
                    'pages' => $pages,
                    'rows' => $scanned,
                ]);

                return FetchWalk::stoppedAt($stop, $pages, $scanned);
            }

            $seenKeys[$continuationKey] = true;
        }
    }

    // A repeated continuation key is the unambiguous no-progress signal: the
    // provider is handing back a cursor it has already served, and following it
    // walks the same page for as long as the process lives.
    /**
     * @param  array<string, true>  $seenKeys
     */
    private function boundReached(int $pages, int $scanned, string $continuationKey, array $seenKeys): ?FetchStop
    {
        return match (true) {
            isset($seenKeys[$continuationKey]) => FetchStop::RepeatedCursor,
            $pages >= self::MAX_PAGES => FetchStop::PageCap,
            $scanned >= self::MAX_ROWS => FetchStop::RowCap,
            default => null,
        };
    }

    private function resolveOwnIban(OpenBankingCredentials $credentials, string $accountUid): string
    {
        $details = $this->client->accountDetails($credentials, $accountUid);

        $accountId = $details['account_id'] ?? null;
        if (is_array($accountId)) {
            $iban = $accountId['iban'] ?? null;
            if (is_string($iban) && $iban !== '') {
                return $iban;
            }
        }

        $topLevelIban = $details['iban'] ?? null;
        if (is_string($topLevelIban) && $topLevelIban !== '') {
            return $topLevelIban;
        }

        throw EnableBankingApiException::missingOwnAccountIban();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function transactionRows(array $response): array
    {
        $raw = $response['transactions'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function continuationKeyFrom(array $response): ?string
    {
        $key = $response['continuation_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function buildDto(EnableBankingTransactionData $row, string $ownIban, int $rowIndex): ?SourceTransactionDto
    {
        $currency = strtoupper($row->currency);
        $isDebit = $row->creditDebitIndicator === 'DBIT';

        // An empty/unknown currency or a non-numeric/over-precise amount
        // must skip this row, not abort the entire fetch generator. The
        // currency is what sets the scale: without it a yen figure parses at
        // the repo-wide hundred, a hundred times what the bank sent.
        $parsedMinor = MoneyInput::tryToMinor($row->amount, $currency);
        $money = $parsedMinor === null ? null : Money::tryOfMinor($parsedMinor, $currency);

        if ($money === null) {
            $this->logger->warning(
                'EnableBankingSourceAdapter: skipping booked row with malformed money.',
                ['currency' => $currency],
            );

            return null;
        }

        $amountMinor = $money->toMinor();

        if ($isDebit && $amountMinor > 0) {
            $amountMinor = -$amountMinor;
        }

        $counterpartyName = $isDebit ? $row->creditorName : $row->debtorName;
        $counterpartyIban = $isDebit ? $row->creditorIban : $row->debtorIban;

        // booking_date drives both bookedAt and postedAt, zeroed to midnight, to
        // match the CAMT adapter. value_date reaches valueDate only, which sits
        // outside the fingerprint tuple.
        $bookedAt = $this->parseRequiredDate($row->bookingDate, 'booking_date');

        // A missing value_date reuses the booking date rather than being parsed
        // as blank, which resolves to the wall clock instead of failing.
        $valueDateRaw = $row->valueDate !== '' ? $row->valueDate : $row->bookingDate;

        return new SourceTransactionDto(
            bookedAt: $bookedAt,
            postedAt: $bookedAt,
            valueDate: $this->parseRequiredDate($valueDateRaw, 'value_date'),
            ownIban: $ownIban,
            counterpartyIban: $counterpartyIban,
            counterpartyName: $counterpartyName,
            currency: $currency,
            amountMinor: $amountMinor,
            sourceRef: null,
            description: $this->collapseRemittance($row->remittanceInformation),
            rawPayload: $this->serialiseEnableBankingFragment($row),
            sourceRowIndex: $rowIndex,
        );
    }

    // The API's own contract is an ISO day, and a parse that accepts anything
    // else accepts '2026-02-31' as 3 March: a booking date the statement never
    // carried, on a row the fingerprint is built from. Refused as loudly as a
    // missing one, since neither is a row this adapter can honestly map.
    private function parseRequiredDate(string $raw, string $fieldName): CarbonImmutable
    {
        return SafeDate::dayOrNull($raw)
            ?? throw EnableBankingApiException::missingTransactionField($fieldName);
    }

    /**
     * @param  list<string>  $remittanceInformation
     */
    private function collapseRemittance(array $remittanceInformation): ?string
    {
        $joined = implode(' ', $remittanceInformation);
        $collapsed = preg_replace('/\s+/u', ' ', $joined);
        $trimmed = trim(is_string($collapsed) ? $collapsed : $joined);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{enable_banking: array<string, mixed>}
     */
    private function serialiseEnableBankingFragment(EnableBankingTransactionData $row): array
    {
        return [
            'enable_banking' => [
                'transactionId' => $row->transactionId,
                'entryReference' => $row->entryReference,
                'status' => $row->status,
                'bankTransactionCode' => [
                    'domain' => $row->bankTransactionDomain,
                    'family' => $row->bankTransactionFamily,
                    'subFamily' => $row->bankTransactionSubFamily,
                ],
            ],
        ];
    }
}
