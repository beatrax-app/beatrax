<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Contracts;

// Three signals: 'light'/'dark' for an explicit OS preference, null
// when the OS itself has no explicit preference (falls through to the
// client-side prefers-color-scheme script), and "no binding
// registered" (local dev) — callers must check app()->bound() first.
interface OsThemeSignal
{
    public function currentOsTheme(): ?string;
}
