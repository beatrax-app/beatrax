<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\System;

/**
 * Reads the operating-system appearance theme via NativePHP's
 * `System::theme()` facade and exposes it as a plain string through
 * the `OsThemeSignal` Public contract.
 *
 * This is the SOLE place the `Native\Desktop\Facades\System` facade
 * is called — the dark-theme layout (`resources/views/layouts/app.blade.php`)
 * resolves the contract from the container for `system`-theme users
 * so it can pick the right server-side `dark` class without
 * importing any `Native\Desktop\*` symbol. The
 * `noNativePhpImportsOutsideDesktopModule` arch invariant keeps that
 * boundary honest; the facade-allow-list carve-out in
 * `BoundaryArchTest` + `phpstan.neon` admits this file by name.
 *
 * `System::theme()` returns a `SystemThemesEnum` instance with
 * `LIGHT` / `DARK` / `SYSTEM` cases. The contract returns:
 *
 *   - `'dark'` when the OS reports an explicit dark preference.
 *   - `'light'` when the OS reports an explicit light preference.
 *   - `null` when the OS sits on `SYSTEM` (no explicit OS-wide choice).
 *     Without the nullable signal a `SYSTEM`-OS user would silently
 *     receive light mode even when their actual `prefers-color-scheme`
 *     resolves to dark — the layout cannot distinguish `light` from
 *     `unknown` once both collapse to the same string. Returning null
 *     lets the layout fall through to the client-side pre-paint
 *     script, which reads `matchMedia` and corrects the class before
 *     first paint.
 */
final class OsThemeProbe implements OsThemeSignal
{
    public function currentOsTheme(): ?string
    {
        return match (System::theme()) {
            SystemThemesEnum::DARK => 'dark',
            SystemThemesEnum::LIGHT => 'light',
            // SYSTEM: no explicit OS-wide preference. Returning null
            // hands the decision to the layout's client-side
            // pre-paint `prefers-color-scheme` script.
            SystemThemesEnum::SYSTEM => null,
        };
    }
}
