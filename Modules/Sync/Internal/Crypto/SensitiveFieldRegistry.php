<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SensitiveFieldRegistry
{
    // Single source-of-truth enumerating the (table, field) pairs the two
    // encryption hooks treat as sensitive content requiring GDK-encryption at
    // rest. New entries land here after an explicit scope decision. Excluded
    // and knowingly-accepted-plaintext columns are documented at @link.
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'transactions.note',
            'transactions.description',
            'transactions.counterparty_name',
            'transactions.counterparty_iban',
            'transactions.raw_payload',
            'counterparties.display_name',
            'counterparties.merchant_name',
            'counterparties.iban',
            'tax_transaction_tags.note',
            'transaction_splits.note',
            // Notification content columns. `id`, `user_id`, `created_at`,
            // `read_at`, `dismissed_at`, and `state` are deliberately NOT
            // listed — the PK is matched in dedup WHERE clauses and the
            // timestamps drive KEK-less pruning/unread counts.
            'notifications.title',
            'notifications.body',
            'notifications.params',
            'notifications.trigger_type',
        ];
    }

    // DI-shim surface — constructor-injected consumers call this instance
    // method rather than the static accessor directly.
    public function isSensitive(string $table, string $field): bool
    {
        return in_array("{$table}.{$field}", self::columns(), true);
    }
}
