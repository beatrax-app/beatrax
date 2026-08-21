<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Enums\Locale;

final class LocaleNegotiator
{
    // The choice of not choosing. Stored as NULL for a user and absent from the
    // session for a guest, so both switchers need a value to name it with;
    // resolve() then falls through to the browser and to English.
    public const string SYSTEM = 'auto';

    public function __construct(private readonly Application $app) {}

    // Resolve the active UI locale in precedence order: an explicit per-user
    // choice wins, then a guest's session choice, then the browser's
    // Accept-Language preference, and finally English. Each candidate is
    // filtered through the supported set before it is used.
    /**
     * @param  string|null  $userLocale  the authenticated user's stored override, or null for "auto"
     * @param  string|null  $sessionLocale  a guest's session-scoped choice, or null
     * @param  string|null  $browserLocale  the already-negotiated Accept-Language best match, or null
     */
    public function resolve(?string $userLocale, ?string $sessionLocale, ?string $browserLocale): string
    {
        foreach ([$userLocale, $sessionLocale, $browserLocale] as $candidate) {
            if ($candidate !== null && Locale::isSupported($candidate)) {
                return $candidate;
            }
        }

        return Locale::DEFAULT;
    }

    // The application, not the translator alone: Livewire replays
    // `app()->getLocale()` on hydrate after the middleware has run, and Carbon
    // keeps its own locale for isoFormat / translatedFormat / diffForHumans,
    // reached only by the LocaleUpdated event this call raises.
    public function apply(string $locale): void
    {
        $this->app->setLocale($locale);
    }

    // "System" is the absence of an override rather than a locale, so it clears
    // the key instead of storing a code — without that arm a reader who left
    // System could never get their browser's language back. A code outside the
    // supported set is dropped rather than remembered.
    public function rememberChoice(Session $session, string $code): void
    {
        if ($code === self::SYSTEM) {
            $session->forget('locale');

            return;
        }

        if (Locale::isSupported($code)) {
            $session->put('locale', $code);
        }
    }
}
