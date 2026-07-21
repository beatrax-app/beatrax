<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\System;

// The sole caller of the System facade for OS-theme reads — the
// dark-theme layout resolves the OsThemeSignal contract instead, so no
// Native\Desktop\* import leaks outside this module.
final class OsThemeProbe implements OsThemeSignal
{
    public function currentOsTheme(): ?string
    {
        return match (System::theme()) {
            SystemThemesEnum::DARK => 'dark',
            SystemThemesEnum::LIGHT => 'light',
            // SYSTEM: no explicit OS-wide preference — hand the
            // decision to the layout's client-side
            // prefers-color-scheme script.
            SystemThemesEnum::SYSTEM => null,
        };
    }
}
