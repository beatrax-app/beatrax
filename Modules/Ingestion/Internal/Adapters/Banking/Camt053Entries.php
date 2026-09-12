<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Genkgo\Camt\Camt053\DTO\Statement;
use Genkgo\Camt\DTO\Entry;
use Genkgo\Camt\DTO\EntryTransactionDetail;

// Which of a statement's entries are the account's money, and which of an
// entry's details are that entry written out. Both answers are in figures the
// file states about itself, and neither was being asked for.
/**
 * @link ../../../../../.docs/features/ingestion/a-statement-that-did-not-check-its-own-arithmetic.md
 */
final class Camt053Entries
{
    private const string PENDING = 'PDNG';

    private const string INFORMATIONAL = 'INFO';

    // The closing balance counts what the bank BOOKED, so an entry the bank
    // states as pending or informational is not in it. Booked anyway, it spends
    // money the account still holds, and books a second time when it settles.
    /**
     * @return list<Entry>
     */
    public function booked(Statement $stmt): array
    {
        $booked = [];

        foreach ($stmt->getEntries() as $entry) {
            if (! in_array($entry->getStatus(), [self::PENDING, self::INFORMATIONAL], true)) {
                $booked[] = $entry;
            }
        }

        return $booked;
    }

    // Null where an entry is denominated in something other than the currency
    // the balances are in, because the total would then be an addition across
    // two and there is nothing the statement's own figures can be checked by.
    public function netMinor(Statement $stmt, ?string $currency): ?int
    {
        if ($currency === null) {
            return null;
        }

        $net = 0;
        foreach ($this->booked($stmt) as $entry) {
            $amount = $entry->getAmount();
            if ($amount->getCurrency()->getCode() !== $currency) {
                return null;
            }

            $net += (int) $amount->getAmount();
        }

        return $net;
    }

    // A batch's children ARE the entry, restated one payment at a time, so a
    // set that does not add up to it is a partial reading of it. Booking that
    // set books the difference away; booking children that state no figure of
    // their own books the whole entry once per child.
    /**
     * @param  array<int, string>  $directions
     * @return array<int, EntryTransactionDetail>
     */
    public function detailsToBook(Entry $entry, array $directions): array
    {
        $details = $entry->getTransactionDetails();
        if (count($details) < 2) {
            return $details;
        }

        $amount = $entry->getAmount();
        $net = $this->netOfDetails($details, $directions, $amount->getCurrency()->getCode());

        return $net === (int) $amount->getAmount() ? $details : [];
    }

    // genkgo signs every figure under an entry off the ENTRY's indicator, so a
    // leg read against the child's own direction has to be re-signed here.
    public function directed(int $minor, ?string $cdi): int
    {
        return match ($cdi) {
            'DBIT' => -abs($minor),
            'CRDT' => abs($minor),
            default => $minor,
        };
    }

    /**
     * @param  array<int, EntryTransactionDetail>  $details
     * @param  array<int, string>  $directions
     */
    private function netOfDetails(array $details, array $directions, string $currency): ?int
    {
        $net = 0;

        foreach ($details as $index => $detail) {
            $money = $detail->getAmount() ?? $detail->getAmountDetails();
            if ($money === null || $money->getCurrency()->getCode() !== $currency) {
                return null;
            }

            $net += $this->directed((int) $money->getAmount(), $directions[$index] ?? null);
        }

        return $net;
    }
}
