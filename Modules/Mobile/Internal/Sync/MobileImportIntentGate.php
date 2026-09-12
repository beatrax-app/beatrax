<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;

/**
 * @link ../../../../.docs/conventions/a-check-another-writer-can-invalidate.md
 */
final readonly class MobileImportIntentGate
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Idempotent on unique(user_id), not on a read before the write: the phone
    // marks the intent from the scan and from the resumed ceremony, and the
    // second of two overlapping calls used to raise rather than no-op. The
    // stamp kept is the first one, which is when the import really began.
    public function markImporting(int $userId): void
    {
        $this->db->connection()->table('mobile_import_intent')->insertOrIgnore([
            'user_id' => $userId,
            'created_at' => Instant::zulu($this->clock->now()),
        ]);
    }

    public function isImporting(int $userId): bool
    {
        return $this->db->connection()
            ->table('mobile_import_intent')
            ->where('user_id', $userId)
            ->exists();
    }

    // Retires the marker once the imported device has genuinely converged.
    // Idempotent, and deliberately the only way the flag ever clears: while
    // it stands, MobileEnsureImportCompleted keeps routing the device back
    // into the ceremony it abandoned.
    public function clearImporting(int $userId): void
    {
        $this->db->connection()
            ->table('mobile_import_intent')
            ->where('user_id', $userId)
            ->delete();
    }
}
