<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

final class YnabTransferMatcher
{
    private const string PREFIX = 'Transfer : ';

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
