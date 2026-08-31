<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;

// Every importer path stamped the reader's reporting currency on the account it
// minted, so a euro statement read by a yen reader opened a yen account and
// /reconcile then asked for the statement balance in yen. The rows themselves
// were always right, so they are the evidence this reads: one account, one
// settled currency, and a default_currency that is not it.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('accounts') || ! $schema->hasTable('transactions')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        // Nothing stored is rewritten here, exactly as SetAccountCurrency does
        // not rewrite one: the account reports a different line and its rows
        // keep the currency they were booked in. The one figure that WOULD
        // change meaning is a reader-typed opening balance, so an account
        // carrying one is left for /settings, where the relabel is shown first.
        foreach ($this->accountsWorthRelabelling($connection) as $row) {
            $settled = $this->theOneSettledCurrency($connection, $row['id']);

            if ($settled === null || $settled === $row['currency']) {
                continue;
            }

            $connection->table('accounts')->where('id', $row['id'])->update(['default_currency' => $settled]);
        }
    }

    public function down(): void
    {
        // Not reversed: the currency this replaced was never a fact about the
        // account, and putting it back would relabel a euro statement in yen.
    }

    /**
     * @return list<array{id: int, currency: string}>
     */
    private function accountsWorthRelabelling(Connection $connection): array
    {
        $rows = [];
        $columns = ['id', 'default_currency', 'opening_balance_minor'];

        foreach ($connection->table('accounts')->orderBy('id')->get($columns) as $row) {
            /** @var stdClass $row */
            if (is_numeric($row->opening_balance_minor) && (int) $row->opening_balance_minor !== 0) {
                continue;
            }

            $currency = is_string($row->default_currency) ? $row->default_currency : '';
            if ($currency === '' || ! is_numeric($row->id)) {
                continue;
            }

            $rows[] = ['id' => (int) $row->id, 'currency' => $currency];
        }

        return $rows;
    }

    // The settled leg, because that is the one the account moved by. An account
    // holding two denominations at once is a Revolut or PayPal wallet doing
    // what it is for, and it has no single denomination to be relabelled to.
    private function theOneSettledCurrency(Connection $connection, int $accountId): ?string
    {
        $found = null;

        $rows = $connection->table('transactions')
            ->where('account_id', $accountId)
            ->distinct()
            ->limit(2)
            ->get(['settled_currency']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = is_string($row->settled_currency) ? $row->settled_currency : '';
            if ($currency === '' || $found !== null) {
                return null;
            }

            $found = $currency;
        }

        return $found;
    }
};
