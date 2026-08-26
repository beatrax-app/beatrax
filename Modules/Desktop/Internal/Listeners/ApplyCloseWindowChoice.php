<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\AppWindow;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Window;

// Neither facade has a constructor-injection seam, so this file sits on the
// BoundaryArchTest + phpstan.neon facade allow-list.
final class ApplyCloseWindowChoice
{
    public function apply(string $choice): void
    {
        if ($choice === WindowCloseBehavior::CHOICE_QUIT) {
            App::quit();

            return;
        }
        if ($choice === WindowCloseBehavior::CHOICE_TRAY) {
            // By id, not by focus: a close arriving from the tray menu, or from
            // a window that has already given up focus, leaves the shell with
            // no focused window to name, and the whole request 500s where
            // nothing is watching for it.
            Window::hide(AppWindow::ID);
        }
    }
}
