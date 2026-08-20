<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Modules\Core\Public\Enums\Locale;

final class LocaleNegotiator
{
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
}
