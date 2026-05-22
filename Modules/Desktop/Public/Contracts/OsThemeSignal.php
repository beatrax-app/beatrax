<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Contracts;

/**
 * The cross-module seam that exposes the operating-system appearance
 * theme to the rest of the app.
 *
 * The concrete implementation (`OsThemeProbe`) lives inside the Desktop
 * module and wraps NativePHP's `System::theme()` facade — keeping every
 * `Native\Desktop\*` import quarantined behind the module boundary. The
 * app layout resolves this contract for `system`-theme users so the
 * desktop bundle can pick the right `dark` class server-side.
 *
 * When the binding is absent — running under Herd, or before the
 * Desktop provider is registered — callers fall back to the client-side
 * `prefers-color-scheme` pre-paint script. Implementations therefore
 * never need a "no signal" return value; the absence of a binding is
 * itself the signal.
 */
interface OsThemeSignal
{
    /**
     * The current operating-system appearance theme.
     *
     * Returns one of `light` or `dark` — the resolved OS preference, not
     * the user's app-level `theme` column.
     */
    public function currentOsTheme(): string;
}
