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
    'NSCameraUsageDescription' => 'Beatrax kasutab kaamerat, et skannida sidumiskoodi, mida kuvatakse su teises seadmes. Midagi ei pildistata ega salvestata.',
    'NSFaceIDUsageDescription' => 'Beatrax kasutab Face ID-d, et su rahaasjad avada ja vabastada võti, millega su andmed on krüpteeritud.',
    'NSLocalNetworkUsageDescription' => 'Beatrax kasutab sinu kohtvõrku, et sünkroonida su rahaasjad otse su teiste Beatraxi seadmetega — selleks ei lahku miski su koduvõrgust.',
];
