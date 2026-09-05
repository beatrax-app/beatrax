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
    'NSCameraUsageDescription' => 'Beatrax koristi kameru za skeniranje koda za uparivanje prikazanog na tvom drugom uređaju. Ništa se ne fotografira niti pohranjuje.',
    'NSFaceIDUsageDescription' => 'Beatrax koristi Face ID za otključavanje tvojih financija i otpuštanje ključa kojim su tvoji podaci šifrirani.',
    'NSLocalNetworkUsageDescription' => 'Beatrax koristi tvoju lokalnu mrežu kako bi tvoje financije sinkronizirao izravno s tvojim drugim Beatrax uređajima — za to ništa ne napušta tvoju kućnu mrežu.',
];
