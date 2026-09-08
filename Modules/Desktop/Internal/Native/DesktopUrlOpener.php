<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Native\Desktop\Contracts\Shell;
use Throwable;

// The desktop half of the opener, and the only place the desktop shell is
// named for it. It used to be named from Modules/Community instead, which put
// a package that ships with the desktop into a module the phone also runs.
final readonly class DesktopUrlOpener implements ExternalUrlOpener
{
    public function __construct(private Shell $shell) {}

    // openExternal() is typed void, so a refusal cannot be told from a launch
    // and the honest answer is "nothing said otherwise". A throw is the one
    // signal there is, and it is reported rather than swallowed.
    public function open(string $url): bool
    {
        try {
            $this->shell->openExternal($url);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
