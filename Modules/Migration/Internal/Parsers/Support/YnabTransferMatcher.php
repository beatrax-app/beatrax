<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
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
     * @param  list<array{rowIndex: int, account: string, date: string, amountMinor: int, counterpartAccount: string}>  $legs
     * @return array<int, int>
     */
    public function pair(array $legs): array
    {
        // Returns a bidirectional row-index-to-row-index map; a leg with no
        // match is absent from the result (an orphan for this textual pass —
        // PairsTransferLegs::pairOrphansForUser() is the authoritative
        // real-domain sweep that runs after promotion).
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

                // A real transfer pair is one inflow leg + one outflow leg —
                // same date/magnitude/cross-referenced account names alone
                // could otherwise pair two erroneous same-sign rows into a
                // bogus transfer link downstream.
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
