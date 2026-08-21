<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;

trait CapturesTransactionMutations
{
    private function handleEdit(TransactionMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'transactions',
                pk: $event->transactionId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleDelete(TransactionMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'transactions',
            pk: $event->transactionId,
        );
    }

    // Writes the CreateRow snapshot directly — a previous
    // writeCreateFields() one-line indirection added no value.
    private function handleCreate(TransactionMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'transactions',
            pk: $event->transactionId,
            fields: $event->dirtyFields,
        );
    }

    private function handleSplitEdit(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'transaction_splits',
                pk: $event->splitId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleSplitDelete(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'transaction_splits',
            pk: $event->splitId,
        );
    }

    private function handleSplitCreate(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'transaction_splits',
            pk: $event->splitId,
            fields: $event->dirtyFields,
        );
    }
}
