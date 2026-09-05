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
    'NSCameraUsageDescription' => 'Beatrax folosește camera pentru a scana codul de asociere afișat pe celălalt dispozitiv al tău. Nimic nu este fotografiat sau salvat.',
    'NSFaceIDUsageDescription' => 'Beatrax folosește Face ID pentru a-ți debloca finanțele și a elibera cheia cu care îți sunt criptate datele.',
    'NSLocalNetworkUsageDescription' => 'Beatrax folosește rețeaua ta locală pentru a-ți sincroniza finanțele direct cu celelalte dispozitive Beatrax ale tale — nimic nu îți părăsește rețeaua de acasă pentru asta.',
];
