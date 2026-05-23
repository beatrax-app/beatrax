<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Window;

/**
 * Applies the user's window-close choice to the native shell.
 *
 * The `CloseWindowPrompt` Livewire component (plan 15-03) dispatches a
 * Livewire-side browser event `close-window-choice` with a `choice`
 * payload of `'quit' | 'tray'` (`WindowCloseBehavior::CHOICE_*`). The
 * JS hook in `layouts/app.blade.php` listens for that event and POSTs
 * to `route('desktop.close-action')`, which invokes this listener.
 *
 * Native-chrome carve-out: this listener calls both `App::quit()` and
 * `Window::current()->hide()` — both are the canonical NativePHP API
 * shapes and have no constructor-injection seam. The file joins the
 * existing `BoundaryArchTest` + `phpstan.neon` facade allow-list.
 *
 * Bundle-only: this listener is only ever invoked from inside the
 * bundle. Outside the bundle there is no native shell to quit / hide.
 *
 * Plan 15-03 deferred this JS glue to 15-04 (the consolidating plan
 * for the "Electron main process → focused webview" seam shared with
 * the file-association deep-link).
 */
final class ApplyCloseWindowChoice
{
    public function apply(string $choice): void
    {
        if ($choice === WindowCloseBehavior::CHOICE_QUIT) {
            App::quit();

            return;
        }
        if ($choice === WindowCloseBehavior::CHOICE_TRAY) {
            Window::current()->hide();
        }
    }
}
