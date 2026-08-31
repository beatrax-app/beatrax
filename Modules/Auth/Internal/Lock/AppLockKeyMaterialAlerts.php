<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;

// The two ways an account ends up holding data no credential still opens, and
// the one rule both are raised under. Both faults persist until somebody acts
// on them, so a per-sign-in copy would bury the first under repeats — which is
// why they are raised from here rather than wherever each is noticed.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
final readonly class AppLockKeyMaterialAlerts
{
    private const string STRANDED_ALERT_KIND = 'auth.lock.key_material_stranded';

    private const string STALE_RECOVERY_ALERT_KIND = 'auth.lock.recovery_wrap_stale';

    public function __construct(
        private SystemAlertWriter $alerts,
    ) {}

    // Nothing will ask for a key on such an install, so every encrypted column
    // renders empty and says nothing about why.
    public function keyMaterialStranded(int $userId): void
    {
        $this->raiseOnce(
            $userId,
            self::STRANDED_ALERT_KIND,
            CopyLine::of('core::alerts.messages.auth_lock_key_material_stranded'),
        );
    }

    public function recoveryWrapStale(int $userId): void
    {
        $this->raiseOnce(
            $userId,
            self::STALE_RECOVERY_ALERT_KIND,
            CopyLine::of('core::alerts.messages.auth_lock_recovery_wrap_stale'),
        );
    }

    // The column keeps the sentence for a peer on a build that cannot read the
    // line beside it; the banner renders these by kind and never reads either.
    private function raiseOnce(int $userId, string $kind, CopyLine $line): void
    {
        $this->alerts->raiseOnceForUser(
            userId: $userId,
            kind: $kind,
            severity: SystemAlertSeverity::Critical->value,
            message: $line->sentence(),
            metadata: StoredCopy::inParams($line),
        );
    }
}
