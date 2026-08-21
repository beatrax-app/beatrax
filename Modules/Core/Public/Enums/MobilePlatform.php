<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

enum MobilePlatform: string
{
    case Android = 'android';

    case Ios = 'ios';

    // The Android WebView can only be handed a body for the URL it already
    // asked for, so a Location header never moves its address bar. The iOS
    // PHPSchemeHandler follows Location with a real navigation instead.
    public function needsClientSideRedirect(): bool
    {
        return match ($this) {
            self::Android => true,
            self::Ios => false,
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
