<?php

declare(strict_types=1);

// iOS reads these out of the app bundle before any PHP runs, keyed by
// Info.plist key rather than by a translation key, and nothing renders them, so
// they sit outside Resources/lang/ where every line must have a call site. The
// reader is a build script that runs with no framework and no translator.
/**
 * @link ../../../../../../.docs/features/mobile/a-purpose-string-in-every-language.md
 */

return [
    'NSCameraUsageDescription' => 'Beatrax uses the camera to scan the pairing code shown on your other device. Nothing is photographed or stored.',
    'NSFaceIDUsageDescription' => 'Beatrax uses Face ID to unlock your finances and release the key your data is encrypted with.',
    'NSLocalNetworkUsageDescription' => 'Beatrax uses your local network to sync your finances directly with your other Beatrax devices — nothing ever leaves your home network for this.',
];
