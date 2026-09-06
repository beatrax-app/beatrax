<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

// A reconciled row is the reader's own assertion that the ledger and a bank
// statement agree, so every mutating action naming one refuses it. These are
// the writers that lock deliberately does not bind, each held against the
// requirement that says so; any other one reaching such a row is a defect.
final class ReconciledRowExemptions
{
    // Keyed by the requirement that mandates the exemption. Each writer carries
    // the pattern that earned its place, so a pin whose proof stops matching is
    // a pin nobody re-read rather than one nobody noticed.
    /** @var array<string, array{reason: string, writers: array<string, string>}> */
    private const array BY_REQUIREMENT = [
        'B8-R8' => [
            'reason' => 'the status writer\'s own un-reconcile, which is the only exit the state graph draws and the escape hatch every refusal points at',
            'writers' => [
                'Modules/Ledger/Public/Services/TransactionStatusWriter.php' => '/ClearedStatus::Reconciled, ClearedStatus::Cleared\)/',
            ],
        ],
        'B4-R23' => [
            'reason' => 'a referential repair clearing a pointer to a record that was removed, which the schema performs on delete rather than any caller naming the row',
            'writers' => [
                'Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php' => '/foreignId\(\'category_id\'\)->nullable\(\)->constrained\(\'categories\'\)->nullOnDelete\(\)/',
                'Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php' => '/->nullOnDelete\(\)/',
            ],
        ],
        'B5-R16' => [
            'reason' => 'the retyping healing pass, which repairs a type the chain resolvers read before they run and must reach every row they will walk',
            // The proof names the pass rather than quoting its statement. It
            // wrote raw SQL until the pass moved behind TransactionTypeWriter,
            // and a proof spelt as SQL reads to the capture guards as a write
            // this file performs — a pin planting evidence in the tree it pins.
            'writers' => [
                'Modules/Chains/Internal/Resolvers/RetypeByAliasResolver.php' => '/\$this->types->retype\(\$user->id,/',
            ],
        ],
        'B1-R15' => [
            'reason' => 'a write to the partner of the named row, and to the survivor of a deleted leg, neither of which is the row the caller named',
            'writers' => [
                'Modules/Transfers/Internal/Services/PairLinkWriter.php' => '/\'pair_transaction_id\' => \$namedId/',
                'Modules/Transfers/Public/Services/PairUnlinker.php' => '/->update\(\[\'type\' => \$newType->value\]\)/',
            ],
        ],
        'B8-R11' => [
            'reason' => 'an arriving sync operation, whose value is the peer\'s merge result rather than this device\'s opinion of it',
            'writers' => [
                'Modules/Sync/Internal/Merge/OpLogEntryApplier.php' => '/->table\(\$table\)/',
                'Modules/Sync/Internal/Merge/SelfReferenceDeferral.php' => '/->table\(\$table\)/',
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function requirements(): array
    {
        return array_keys(self::BY_REQUIREMENT);
    }

    // Repo-relative path => the requirement that admits it, so a caller asking
    // about one writer never has to walk the whole list to answer.
    /**
     * @return array<string, string>
     */
    public static function writers(): array
    {
        $writers = [];

        foreach (self::BY_REQUIREMENT as $requirement => $exemption) {
            foreach (array_keys($exemption['writers']) as $file) {
                $writers[$file] = $requirement;
            }
        }

        return $writers;
    }

    /**
     * @return array<string, string>
     */
    public static function proofs(): array
    {
        $proofs = [];

        foreach (self::BY_REQUIREMENT as $exemption) {
            foreach ($exemption['writers'] as $file => $pattern) {
                $proofs[$file] = $pattern;
            }
        }

        return $proofs;
    }

    /**
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        $reasons = [];

        foreach (self::BY_REQUIREMENT as $requirement => $exemption) {
            $reasons[$requirement] = $exemption['reason'];
        }

        return $reasons;
    }
}
