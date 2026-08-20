<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\System;

// The sole caller of the System facade for OS-theme reads; everything outside
// this module resolves the OsThemeSignal contract instead.
final class OsThemeProbe implements OsThemeSignal
{
    public function currentOsTheme(): ?string
    {
        return match (System::theme()) {
            SystemThemesEnum::DARK => 'dark',
            SystemThemesEnum::LIGHT => 'light',
            // No OS-wide preference — the layout's prefers-color-scheme script decides.
            SystemThemesEnum::SYSTEM => null,
        };
    }
}
