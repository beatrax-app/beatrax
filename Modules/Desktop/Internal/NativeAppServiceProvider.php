<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal;

use Native\Desktop\Contracts\WindowManager;

/**
 * The NativePHP-booted application provider.
 *
 * NativePHP resolves this class from the `provider` key in
 * `config/nativephp.php` (via `app(config('nativephp.provider'))`) and
 * calls `boot()` once the native shell has started. Because the class is
 * resolved through the container, its constructor dependencies are
 * autowired — so the native-chrome managers are taken by constructor
 * injection rather than through the `Native\Desktop\Facades\*` facades,
 * keeping the project's DI-only rule intact.
 *
 * This is the sole sanctioned home for `Native\Desktop\*` symbols; every
 * other module file must stay free of them — the boundary is enforced by
 * the `noNativePhpImportsOutsideDesktopModule` arch invariant.
 *
 * `boot()` opens the single application window that renders the diederik
 * web UI: the window defaults to the app root URL and the `app.name`
 * title. Stateful window geometry, the app menu, and the system tray are
 * wired by a later plan.
 */
final class NativeAppServiceProvider
{
    public function __construct(
        private readonly WindowManager $windows,
    ) {}

    public function boot(): void
    {
        $this->windows->open('main');
    }
}
