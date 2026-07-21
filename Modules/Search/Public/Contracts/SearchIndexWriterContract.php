<?php

declare(strict_types=1);

namespace Modules\Search\Public\Contracts;

// Public write contract so the Tax module's TagTransaction action can
// reindex without crossing into Search Internal. Index updates are
// always synchronous — searchable immediately, no queue dependency.
interface SearchIndexWriterContract
{
    // Idempotent upsert. The caller MUST pass the authenticated
    // actor's user id; a mismatch against the row's owner is a no-op,
    // so a forged transaction id can never rebuild another user's doc.
    public function upsertForTransaction(int $transactionId, int $actorUserId): void;

    // Removes the FTS5 document on permanent deletion. The actor's
    // user id is verified against the stored doc's owner; a mismatch
    // is a no-op.
    public function deleteForTransaction(int $transactionId, int $actorUserId): void;
}
