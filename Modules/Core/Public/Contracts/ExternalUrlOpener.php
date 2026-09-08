<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// Handing a URL to the operating system, asked the same way on every platform.
// The point of the contract is that the question is platform-free: a phone, a
// desktop shell and a browser tab each answer it differently, and only one of
// them ships the desktop package that used to type this seam.
interface ExternalUrlOpener
{
    // True only where the platform confirmed it took the URL. A shell that
    // opens nothing answers false rather than staying quiet, because the
    // caller's next line is what the reader is told happened.
    public function open(string $url): bool;
}
