<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Support;

use Modules\Migration\Internal\Enums\MigrationEntityType;

// Which whole sentence names a conflicted field, for the writer that records
// the conflict and the reader that rebuilds the label. One key per pair, not an
// entity noun glued to a field noun: nine shipped locales decline the second
// after the first, and two keys leave a translator neither case nor order.
final class ConflictLabel
{
    public static function keyFor(string $entityType, string $fieldName): string
    {
        return match (true) {
            $entityType === MigrationEntityType::BudgetAssignment->value => 'migration::unmapped.conflict.budget_assignment',
            $entityType === MigrationEntityType::Category->value && $fieldName === 'name' => 'migration::unmapped.conflict.category_name',
            $entityType === MigrationEntityType::Account->value && $fieldName === 'name' => 'migration::unmapped.conflict.account_name',
            $entityType === MigrationEntityType::Transaction->value && $fieldName === 'amount_minor' => 'migration::unmapped.conflict.transaction_amount',
            $entityType === MigrationEntityType::Transaction->value && $fieldName === 'description' => 'migration::unmapped.conflict.transaction_description',
            default => 'migration::unmapped.conflict.other',
        };
    }
}
