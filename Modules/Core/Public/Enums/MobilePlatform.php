<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

enum MobilePlatform: string
{
    case Android = 'android';

    case Ios = 'ios';

    // Neither shell moves its address bar on a Location header. Android's
    // WebView can only be handed a body for the URL it already asked for. iOS
    // navigates only where the target has no scheme, and Laravel's redirects
    // are absolute, so a php:// target is fetched onto the old address instead.
    public function needsClientSideRedirect(): bool
    {
        return match ($this) {
            self::Android, self::Ios => true,
        };
    }

    // Whether an `<a download>` in the WebView reaches the reader. The iOS
    // shell answers that navigation with `.download` and presents the system
    // share sheet, so "Save to Files" lands outside the app. The Android shell
    // registers no DownloadListener, so the same click is dropped in silence.
    public function savesWebViewDownloads(): bool
    {
        return match ($this) {
            self::Android => false,
            self::Ios => true,
        };
    }
}
