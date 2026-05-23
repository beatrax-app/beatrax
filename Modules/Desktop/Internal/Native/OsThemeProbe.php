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
 * `LIGHT` / `DARK` / `SYSTEM` cases. The contract returns one of
 * `light` or `dark` — the resolved OS preference. When the OS sits in
 * `SYSTEM` (no explicit OS-wide preference, rare) the probe falls
 * back to `light`; the pre-paint `prefers-color-scheme` script in the
 * layout corrects it client-side before first paint.
 */
final class OsThemeProbe implements OsThemeSignal
{
    public function currentOsTheme(): string
    {
        $theme = System::theme();

        return match ($theme) {
            SystemThemesEnum::DARK => 'dark',
            // LIGHT and SYSTEM both resolve to `light` here — `SYSTEM`
            // means the OS has no explicit choice and the layout's
            // pre-paint script will correct it before first paint.
            SystemThemesEnum::LIGHT, SystemThemesEnum::SYSTEM => 'light',
        };
    }
}
