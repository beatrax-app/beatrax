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
    'NSCameraUsageDescription' => 'Beatrax bruger kameraet til at scanne parringskoden, der vises på din anden enhed. Der bliver hverken taget eller gemt billeder.',
    'NSFaceIDUsageDescription' => 'Beatrax bruger Face ID til at låse din økonomi op og frigive nøglen, dine data er krypteret med.',
    'NSLocalNetworkUsageDescription' => 'Beatrax bruger dit lokale netværk til at synkronisere din økonomi direkte med dine andre Beatrax-enheder — intet forlader dit hjemmenetværk for at gøre det.',
];
