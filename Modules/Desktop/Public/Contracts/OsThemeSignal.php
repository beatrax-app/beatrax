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
 * The contract distinguishes three signals:
 *
 *   - `'light'` / `'dark'`: the OS reported an explicit appearance
 *     preference; the layout binds the class server-side.
 *   - `null`: the binding resolved but the OS itself has no explicit
 *     preference (NativePHP's `SystemThemesEnum::SYSTEM` case). The
 *     layout falls through to the client-side `prefers-color-scheme`
 *     pre-paint script — the script's `matchMedia` read is the
 *     authoritative source in this no-signal case.
 *   - "no binding registered": running under Herd, or before the
 *     Desktop provider is registered. Callers must check
 *     `app()->bound(OsThemeSignal::class)` before resolving; the
 *     absence of a binding IS itself the no-signal signal.
 */
interface OsThemeSignal
{
    /**
     * The current operating-system appearance theme.
     *
     * Returns `'light'` or `'dark'` for an explicit OS preference, or
     * `null` when the OS has no explicit choice and the layout should
     * fall back to the client-side `prefers-color-scheme` script.
     */
    public function currentOsTheme(): ?string;
}
