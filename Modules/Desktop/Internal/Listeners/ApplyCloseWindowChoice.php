<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

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
            Window::current()->hide();
        }
    }
}
