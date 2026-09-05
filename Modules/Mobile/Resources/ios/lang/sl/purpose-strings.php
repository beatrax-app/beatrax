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
    'NSCameraUsageDescription' => 'Beatrax uporablja kamero za skeniranje kode za seznanitev, prikazane na tvoji drugi napravi. Nič se ne fotografira in nič se ne shrani.',
    'NSFaceIDUsageDescription' => 'Beatrax uporablja Face ID, da odklene tvoje finance in sprosti ključ, s katerim so tvoji podatki šifrirani.',
    'NSLocalNetworkUsageDescription' => 'Beatrax uporablja tvoje lokalno omrežje, da tvoje finance sinhronizira neposredno s tvojimi drugimi napravami Beatrax — za to nič ne zapusti tvojega domačega omrežja.',
];
