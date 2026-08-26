<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The system_alerts.kind values the desktop updater emits — all three share
// the dotted `update.*` prefix and the skip-list carve-out documented in the
// skipped_update_versions migration. This is their one canonical spelling so
// a writer can never drift back to e.g. `update_available`.
enum UpdateAlertKind: string
{
    case Available = 'update.available';

    case Stale = 'update.stale';

    case Critical = 'update.critical';

    // Severity is a property of the kind, not a separate decision a writer
    // makes: an unsupported-age release is a warning, a security-fix release
    // is critical, and a routine one is informational.
    public function severity(): SystemAlertSeverity
    {
        return match ($this) {
            self::Available => SystemAlertSeverity::Info,
            self::Stale => SystemAlertSeverity::Warning,
            self::Critical => SystemAlertSeverity::Critical,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
