<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;

final readonly class MobileImportIntentGate
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // Idempotent - a second call for the same user is a no-op, never a
    // duplicate row.
    public function markImporting(int $userId): void
    {
        $exists = $this->db->connection()
            ->table('mobile_import_intent')
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return;
        }

        $this->db->connection()->table('mobile_import_intent')->insert([
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
