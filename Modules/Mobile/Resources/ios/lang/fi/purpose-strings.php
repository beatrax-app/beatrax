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
    'NSCameraUsageDescription' => 'Beatrax käyttää kameraa lukeakseen laiteparin koodin, joka näkyy toisessa laitteessasi. Mitään ei valokuvata eikä tallenneta.',
    'NSFaceIDUsageDescription' => 'Beatrax käyttää Face ID:tä avatakseen taloutesi ja vapauttaakseen avaimen, jolla tietosi on salattu.',
    'NSLocalNetworkUsageDescription' => 'Beatrax käyttää lähiverkkoasi synkronoidakseen taloutesi suoraan muiden Beatrax-laitteidesi kanssa — mikään ei poistu kotiverkostasi tätä varten.',
];
