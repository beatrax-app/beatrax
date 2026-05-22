<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

/**
 * The NativePHP-booted application provider.
 *
 * NativePHP resolves this class from the `provider` key in
 * `config/nativephp.php` and calls `boot()` once the native shell has
 * started. It is the sole sanctioned home for `Native\Desktop\Facades\*`
 * calls: NativePHP's native chrome (windows, menus, the system tray) can
 * only be configured through those facades, and the project's DI-only
 * rule has no constructor-injection seam for a facade that NativePHP
 * invokes outside the container lifecycle. Every other module file must
 * stay free of `Native\Desktop\*` symbols — the boundary is enforced by
 * the `noNativePhpImportsOutsideDesktopModule` arch invariant.
 *
 * `boot()` is intentionally empty for now; the window, app menu, and
 * system-tray configuration is wired by a later plan.
 */
final class NativeAppServiceProvider
{
    public function boot(): void
    {
        //
    }
}
