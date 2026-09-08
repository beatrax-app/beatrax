<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Native\Mobile\Facades\Browser;
use Throwable;

// A phone can open a URL, and before this it was the one platform that could
// not: the seam was typed by the desktop package, which the mobile Composer
// root does not install, so asking for an opener was a fatal rather than a
// no-op.
final readonly class MobileUrlOpener implements ExternalUrlOpener
{
    // Browser::open() calls Browser.Open over the bridge and reads the
    // {"success":true} the shell answers with, so unlike the desktop side this
    // one really does know whether the URL was taken.
    public function open(string $url): bool
    {
        if (! class_exists(Browser::class)) {
            return false;
        }

        // Compared against true rather than returned straight through: the
        // mobile package is absent from the repository Composer root, so the
        // facade's answer is untyped there, and only an explicit yes is one.
        try {
            return Browser::open($url) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
