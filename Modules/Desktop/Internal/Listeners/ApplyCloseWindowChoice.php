<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\WindowCloseBehavior;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Window;

// Calls both App::quit() and Window::current()->hide() directly — both
// are the canonical NativePHP API shapes with no constructor-injection
// seam, so this file is on the BoundaryArchTest + phpstan.neon facade
// allow-list. Only ever invoked inside the bundle.
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
