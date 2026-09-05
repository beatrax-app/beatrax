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
    'NSCameraUsageDescription' => 'Beatrax bruker kameraet til å skanne paringskoden som vises på den andre enheten din. Ingenting fotograferes eller lagres.',
    'NSFaceIDUsageDescription' => 'Beatrax bruker Face ID til å låse opp økonomien din og frigi nøkkelen dataene dine er kryptert med.',
    'NSLocalNetworkUsageDescription' => 'Beatrax bruker det lokale nettverket ditt til å synkronisere økonomien din direkte med de andre Beatrax-enhetene dine — ingenting forlater hjemmenettverket ditt for dette.',
];
