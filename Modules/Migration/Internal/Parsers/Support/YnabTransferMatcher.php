<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

/**
 * Detects and pairs YNAB4/nYNAB transfer rows. A transfer has no explicit
 * link column — it is two ordinary Register.csv rows whose `Payee` cell
 * carries the `"Transfer : <AccountName>"` convention (RESEARCH.md Format
 * Schemas / Req 5), one per side of the account pair. This is the CSV-domain
 * analog of `Modules\Transfers\Public\Contracts\PairsTransferLegs`, run
 * BEFORE `RecordTransactions` since the pairing key here is textual
 * (date + amount magnitude + counterpart account name), not a
 * `pair_transaction_id` column.
 */
final class YnabTransferMatcher
{
    private const PREFIX = 'Transfer : ';

    public function isTransferPayee(string $payee): bool
    {
        return str_starts_with(trim($payee), self::PREFIX);
    }

    public function counterpartAccountName(string $payee): ?string
    {
        $trimmed = trim($payee);

        return $this->isTransferPayee($trimmed) ? substr($trimmed, strlen(self::PREFIX)) : null;
    }

    /**
     * Pairs transfer legs by (date, |amount|, counterpart account name <->
     * own account name). Returns a bidirectional row-index-to-row-index map;
     * a leg with no match is simply absent from the result (an orphan for
     * this textual pass — `PairsTransferLegs::pairOrphansForUser()` is the
     * authoritative real-domain sweep that runs after promotion, per
     * RESEARCH.md's Pitfall on this ordering).
     *
     * @param  list<array{rowIndex: int, account: string, date: string, amountMinor: int, counterpartAccount: string}>  $legs
     * @return array<int, int>
     */
    public function pair(array $legs): array
    {
        $pairs = [];
        $used = [];

        foreach ($legs as $i => $legA) {
            if (isset($used[$legA['rowIndex']])) {
                continue;
            }

            foreach ($legs as $j => $legB) {
                if ($i === $j || isset($used[$legB['rowIndex']])) {
                    continue;
                }

                // WR-05: a real transfer pair is one inflow leg + one outflow
                // leg — same date/magnitude/cross-referenced account names is
                // not sufficient on its own (corrupted/hand-edited source
                // data could otherwise pair two erroneous same-sign
                // "Transfer :" rows into a bogus transfer_in/transfer_out
                // link downstream in PromoteStagingToDomain).
                $oppositeSign = ($legA['amountMinor'] < 0) !== ($legB['amountMinor'] < 0);

                $matches = $legA['date'] === $legB['date']
                    && abs($legA['amountMinor']) === abs($legB['amountMinor'])
                    && $oppositeSign
                    && $legA['counterpartAccount'] === $legB['account']
                    && $legB['counterpartAccount'] === $legA['account'];

                if ($matches) {
                    $pairs[$legA['rowIndex']] = $legB['rowIndex'];
                    $pairs[$legB['rowIndex']] = $legA['rowIndex'];
                    $used[$legA['rowIndex']] = true;
                    $used[$legB['rowIndex']] = true;

                    break;
                }
            }
        }

        return $pairs;
    }
}
