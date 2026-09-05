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
    'NSCameraUsageDescription' => 'Beatrax izmanto kameru, lai noskenētu savienošanas kodu, kas redzams jūsu otrā ierīcē. Nekas netiek fotografēts vai saglabāts.',
    'NSFaceIDUsageDescription' => 'Beatrax izmanto Face ID, lai atbloķētu jūsu finanses un atbrīvotu atslēgu, ar kuru ir šifrēti jūsu dati.',
    'NSLocalNetworkUsageDescription' => 'Beatrax izmanto jūsu lokālo tīklu, lai sinhronizētu jūsu finanses tieši ar jūsu pārējām Beatrax ierīcēm — nekas šim nolūkam neatstāj jūsu mājas tīklu.',
];
