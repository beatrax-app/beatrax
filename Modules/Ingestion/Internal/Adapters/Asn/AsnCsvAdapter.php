<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Throwable;

/**
 * Streaming parser for the ASN Online Bankieren "CSV met IBAN" export.
 *
 * The adapter delegates pre-parse validation to HeaderSniffer (rejecting
 * non-CSV uploads with a user-readable message), reads the file lazily via
 * `league/csv` Reader + CharsetConverter (UTF-8 round-trip, safe for legacy
 * windows-1252 exports too), and yields one SourceTransactionDto per row.
 *
 * Yielding is intentional: a multi-year ASN history can run to tens of
 * thousands of rows. The Import pipeline consumes the generator once and
 * never materializes the whole list.
 *
 * Amounts go through AsnAmountParser (regex + integer arithmetic, no float
 * path — IEEE-754 corrupts cent precision). Counterparty names that arrive
 * empty stay null in the DTO; substituting the `_no_counterparty` sentinel
 * is the Normalize stage's responsibility.
 */
final class AsnCsvAdapter implements SourceAdapter
{
    public function __construct(
        private readonly AsnAmountParser $amounts,
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return AsnCsvHeaderProfile::FORMAT;
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        // Sniff first — throws SniffMismatchException with a user-facing
        // message if the file doesn't match the ASN CSV signature.
        $this->sniffer->sniff($localPath, AsnCsvHeaderProfile::FORMAT);

        $reader = Reader::from($localPath, 'r');
        $reader->setDelimiter(AsnCsvHeaderProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, AsnCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8');

        $index = 0;
        foreach ($reader->getRecords() as $record) {
            // After setHeaderOffset, records are associative arrays keyed by
            // header name. Coerce to a list keyed by integer column position
            // so AsnCsvColumnMap constants resolve directly.
            $row = $this->normaliseRow($record);

            try {
                $postedAt = $this->parseDate($row[AsnCsvColumnMap::POSTED_DATE]);
                $valueDate = $this->parseDate($row[AsnCsvColumnMap::VALUE_DATE]);
                $amountMinor = $this->amounts->parseMinor($row[AsnCsvColumnMap::AMOUNT]);
            } catch (Throwable $e) {
                throw new InvalidAmountException(
                    sprintf('Row %d: %s', $index, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $ownIban = $row[AsnCsvColumnMap::OWN_IBAN];
            // Resolve so the upload wizard can branch on Unknown before the
            // user confirms the import. The adapter does not use the
            // resolution itself — IBAN → account_id mapping is the
            // Normalize stage's job.
            $accounts->resolve($ownIban);

            $currency = $row[AsnCsvColumnMap::MUTATION_CURRENCY];
            if ($currency === '') {
                $currency = 'EUR';
            }

            yield new SourceTransactionDto(
                bookedAt: $postedAt->startOfDay(),
                postedAt: $postedAt,
                valueDate: $valueDate,
                ownIban: $ownIban,
                counterpartyIban: $this->nullIfEmpty($row[AsnCsvColumnMap::COUNTERPARTY_IBAN]),
                counterpartyName: $this->nullIfEmpty(trim($row[AsnCsvColumnMap::COUNTERPARTY_NAME])),
                currency: $currency,
                amountMinor: $amountMinor,
                sourceRef: $this->nullIfEmpty($row[AsnCsvColumnMap::SEQUENCE_NUMBER]),
                description: $this->joinDescription($row),
                rawPayload: $row,
                sourceRowIndex: $index,
            );

            $index++;
        }
    }

    /**
     * Coerces a league/csv record (associative under setHeaderOffset, keyed
     * by header name) into a positional list of strings. The library's
     * contract for 9.x is to yield strings or null only — anything else is
     * a precondition violation and is rejected loudly rather than papered
     * over with type juggling.
     *
     * @param  mixed  $record  Whatever league/csv yielded for one row.
     * @return array<int, string>
     */
    private function normaliseRow(mixed $record): array
    {
        if (! is_array($record)) {
            throw new InvalidAmountException('Unexpected non-array record from CSV reader.');
        }

        $row = [];
        foreach (array_values($record) as $cell) {
            if ($cell === null) {
                $row[] = '';
            } elseif (is_string($cell)) {
                $row[] = $cell;
            } else {
                throw new InvalidAmountException(sprintf(
                    'Unexpected non-string cell in CSV row (got %s).',
                    get_debug_type($cell),
                ));
            }
        }

        return $row;
    }

    private function parseDate(string $cell): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!'.AsnCsvHeaderProfile::DATE_FORMAT, $cell);
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidAmountException(sprintf(
                "Cannot parse date '%s' (expected format %s)",
                $cell,
                AsnCsvHeaderProfile::DATE_FORMAT,
            ));
        }

        return $parsed;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Joins the payment reference and the free-text description, normalises
     * embedded CR/LF (ASN historically emits literal `\r`), and collapses
     * runs of whitespace.
     *
     * @param  array<int, string>  $row
     */
    private function joinDescription(array $row): ?string
    {
        $parts = [];
        foreach ([AsnCsvColumnMap::PAYMENT_REF, AsnCsvColumnMap::DESCRIPTION] as $col) {
            $value = trim($row[$col]);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts === []) {
            return null;
        }

        $combined = implode(' / ', $parts);
        $combined = str_replace(["\r", "\n"], ' ', $combined);
        $collapsed = preg_replace('/\s+/u', ' ', $combined);

        return is_string($collapsed) ? trim($collapsed) : trim($combined);
    }
}
