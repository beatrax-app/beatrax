<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Database\DatabaseManager;

// Its own collaborator rather than two methods on MobileLockGateway: the mobile
// cold-start vault needs nothing but this column, and taking the whole gateway
// for it put the vault inside the graph the app-lock provisioner is built from
// — so the provisioner could not name the vault back without a build cycle.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
final readonly class ColdStartEnrolmentFlag
{
    public function __construct(private DatabaseManager $db) {}

    public function mark(int $userId, bool $enrolled): void
    {
        $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->update(['cold_start_biometric_enrolled' => $enrolled]);
    }

    public function isEnrolled(int $userId): bool
    {
        $row = $this->db->connection()->table('user_app_lock_configs')
            ->where('user_id', $userId)
            ->first(['cold_start_biometric_enrolled']);

        return $row !== null && (bool) $row->cold_start_biometric_enrolled;
    }
}
