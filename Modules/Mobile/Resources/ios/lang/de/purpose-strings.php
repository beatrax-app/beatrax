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
    'NSCameraUsageDescription' => 'Beatrax nutzt die Kamera, um den Kopplungscode zu scannen, der auf deinem anderen Gerät angezeigt wird. Es wird nichts fotografiert oder gespeichert.',
    'NSFaceIDUsageDescription' => 'Beatrax nutzt Face ID, um deine Finanzen zu entsperren und den Schlüssel freizugeben, mit dem deine Daten verschlüsselt sind.',
    'NSLocalNetworkUsageDescription' => 'Beatrax nutzt dein lokales Netzwerk, um deine Finanzen direkt mit deinen anderen Beatrax-Geräten zu synchronisieren — dafür verlässt nichts dein Heimnetzwerk.',
];
