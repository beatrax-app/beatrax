<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The system_alerts.kind values the desktop updater emits — all three share
// the dotted `update.*` prefix and the skip-list carve-out documented in the
// skipped_update_versions migration. This is their one canonical spelling so
// a writer can never drift back to e.g. `update_available`.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
enum UpdateAlertKind: string
{
    case Available = 'update.available';

    case Stale = 'update.stale';

    case Critical = 'update.critical';
}
