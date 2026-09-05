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
    'NSCameraUsageDescription' => 'Beatrax používa fotoaparát na naskenovanie párovacieho kódu zobrazeného na tvojom druhom zariadení. Nič sa nefotí ani neukladá.',
    'NSFaceIDUsageDescription' => 'Beatrax používa Face ID, aby odomkol tvoje financie a uvoľnil kľúč, ktorým sú tvoje dáta zašifrované.',
    'NSLocalNetworkUsageDescription' => 'Beatrax používa tvoju lokálnu sieť, aby tvoje financie synchronizoval priamo s tvojimi ďalšími zariadeniami s Beatraxom — kvôli tomu nič neopúšťa tvoju domácu sieť.',
];
