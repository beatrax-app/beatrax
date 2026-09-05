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
    'NSCameraUsageDescription' => 'A Beatrax a kamerát a másik eszközödön megjelenő párosítási kód beolvasására használja. Semmiről nem készül fénykép, és semmi nem kerül mentésre.',
    'NSFaceIDUsageDescription' => 'A Beatrax a Face ID-t a pénzügyeid feloldására és annak a kulcsnak a kiadására használja, amellyel az adataid titkosítva vannak.',
    'NSLocalNetworkUsageDescription' => 'A Beatrax a helyi hálózatodat használja, hogy a pénzügyeidet közvetlenül a többi Beatrax-eszközöddel szinkronizálja — ehhez semmi nem hagyja el az otthoni hálózatodat.',
];
