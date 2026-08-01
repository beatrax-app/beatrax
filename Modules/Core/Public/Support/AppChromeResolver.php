<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Theme;
use Modules\Desktop\Public\Contracts\OsThemeSignal;

// Resolves the dark/locale chrome each page shell renders into its <html>
// tag and head. Guests and unset columns fall to Theme::DEFAULT; "system"
// consults the desktop OsThemeSignal when bound and otherwise defers to the
// pre-paint script. Replaces the @php block the four layouts hand-rolled.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class AppChromeResolver
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Translator $translator,
        private readonly Container $container,
    ) {}

    public function resolve(): AppChrome
    {
        $theme = Theme::coerce(
            $this->currentUser->isAuthenticated() ? $this->currentUser->user()->theme : null,
        );

        $osTheme = null;
        if ($theme === Theme::System && $this->container->bound(OsThemeSignal::class)) {
            $osTheme = $this->container->make(OsThemeSignal::class)->currentOsTheme();
        }

        return new AppChrome(
            isDark: $theme->isDark($osTheme),
            needsPrePaintScript: $theme->needsPrePaintScript($osTheme),
            locale: $this->translator->getLocale(),
        );
    }
}
