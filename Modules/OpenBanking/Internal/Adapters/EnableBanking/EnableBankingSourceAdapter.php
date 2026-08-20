<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EnableBankingSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(
        private readonly EnableBankingHttpClient $client,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $ownIban = $this->resolveOwnIban($institutionId);

        $rowIndex = 0;
        $continuationKey = null;

        do {
            $response = $this->client->transactions($institutionId, $window, $continuationKey);
            $rows = EnableBankingTransactionData::collectionFromArray($this->transactionRows($response));

            foreach ($rows as $row) {
                if (! $row->isBooked()) {
                    continue;
                }

                $dto = $this->buildDto($row, $ownIban, $rowIndex);
                if ($dto === null) {
                    // A single malformed-money booked row is skipped here
                    // (logged in buildDto) rather than aborting the whole
                    // generator-driven fetch mid-import.
                    continue;
                }

                yield $dto;
                $rowIndex++;
            }

            $continuationKey = $this->continuationKeyFrom($response);
        } while ($continuationKey !== null);
    }

    private function resolveOwnIban(string $institutionId): string
    {
        $details = $this->client->accountDetails($institutionId);

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
        // must skip this row, not abort the entire fetch generator.
        $parsedMinor = MoneyInput::tryToMinor($row->amount);
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

        // booking_date drives BOTH bookedAt and postedAt, zeroed to midnight
        // — the single most important field substitution for dedup parity
        // with the CAMT adapter. value_date is carried on valueDate only,
        // which is outside the fingerprint hash tuple.
        $bookedAt = $this->parseRequiredDate($row->bookingDate, 'booking_date');

        // A row missing value_date reuses the booking date rather than
        // falling through to CarbonImmutable::parse(''), which silently
        // resolves to the wall clock rather than a response-derived date.
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

    private function parseRequiredDate(string $raw, string $fieldName): CarbonImmutable
    {
        if ($raw === '') {
            throw EnableBankingApiException::missingTransactionField($fieldName);
        }

        return CarbonImmutable::parse($raw)->startOfDay();
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
