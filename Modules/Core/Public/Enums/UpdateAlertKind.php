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

    // Not an availability kind: this one says an update was downloaded and
    // then refused. It carries `refusedVersion` rather than `latestVersion`,
    // so the availability guard that reads this enum cannot mistake a refusal
    // for "the reader has already been told about this release".
    case Refused = 'update.refused';

    // Severity is a property of the kind, not a separate decision a writer
    // makes: an unsupported-age release is a warning, a security-fix release
    // is critical, and a routine one is informational.
    public function severity(): SystemAlertSeverity
    {
        return match ($this) {
            self::Available => SystemAlertSeverity::Info,
            self::Stale => SystemAlertSeverity::Warning,
            self::Critical, self::Refused => SystemAlertSeverity::Critical,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
