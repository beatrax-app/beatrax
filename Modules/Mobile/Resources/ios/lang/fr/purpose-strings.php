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
    'NSCameraUsageDescription' => 'Beatrax utilise l’appareil photo pour scanner le code d’appairage affiché sur ton autre appareil. Rien n’est photographié ni conservé.',
    'NSFaceIDUsageDescription' => 'Beatrax utilise Face ID pour déverrouiller tes finances et libérer la clé avec laquelle tes données sont chiffrées.',
    'NSLocalNetworkUsageDescription' => 'Beatrax utilise ton réseau local pour synchroniser tes finances directement avec tes autres appareils Beatrax — rien ne quitte ton réseau domestique pour cela.',
];
