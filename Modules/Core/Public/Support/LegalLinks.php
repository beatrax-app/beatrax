<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The published legal documents, as one place a view can @use. Both stores
// require the privacy policy to be reachable from inside the app and not only
// from the store listing, so the URL is shipped in the build rather than only
// in App Store Connect and Play Console.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class LegalLinks
{
    public const string PRIVACY_POLICY_URL = 'https://beatrax.app/privacy';
}
