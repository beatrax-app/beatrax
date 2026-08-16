<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Theme;
use Modules\Desktop\Public\Contracts\OsThemeSignal;
use Throwable;

// Resolves the dark/locale chrome each page shell renders into its <html>
// tag and head. Guests and unset columns fall to Theme::DEFAULT; "system"
// consults the desktop OsThemeSignal when bound and otherwise defers to the
// pre-paint script. Replaces the @php block the four layouts hand-rolled.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class AppChromeResolver
{
    // Written by the client from matchMedia and read here on the next
    // request, so both sides decide from the same value.
    public const SCHEME_COOKIE = 'beatrax_scheme';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Translator $translator,
        private readonly Container $container,
    ) {}

    // Null until the client has reported once; the pre-paint script covers
    // that first render and every one after it agrees with this value.
    private function reportedScheme(): ?string
    {
        if (! $this->container->bound(Request::class)) {
            return null;
        }

        try {
            $reported = $this->container->make(Request::class)->cookie(self::SCHEME_COOKIE);
        } catch (Throwable) {
            // Console contexts and test doubles can hand back something that
            // is not a Request at all; no answer is the safe one, and the
            // pre-paint script still decides.
            return null;
        }

        return $reported === 'dark' || $reported === 'light' ? $reported : null;
    }

    public function resolve(): AppChrome
    {
        $theme = Theme::coerce(
            $this->currentUser->isAuthenticated() ? $this->currentUser->user()->theme : null,
        );

        $osTheme = null;

        if ($theme === Theme::System) {
            // The client's own prefers-color-scheme, reported on a previous
            // request: the only source available at first byte that cannot
            // disagree with what the page decides moments later. A native
            // probe answers per request and can differ between renders.
            $osTheme = $this->reportedScheme();

            if ($osTheme === null && $this->container->bound(OsThemeSignal::class)) {
                $osTheme = $this->container->make(OsThemeSignal::class)->currentOsTheme();
            }
        }

        return new AppChrome(
            isDark: $theme->isDark($osTheme),
            needsPrePaintScript: $theme->needsPrePaintScript($osTheme),
            locale: $this->translator->getLocale(),
        );
    }
}
