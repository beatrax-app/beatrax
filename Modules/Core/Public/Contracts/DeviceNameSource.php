<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// A platform-supplied name for THIS device, when the platform knows one.
// Null means "no better answer than the neutral OS-family label" — the
// detector's own fallback then applies, so implementations never guess.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
interface DeviceNameSource
{
    public function name(): ?string;
}
