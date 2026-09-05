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
    'NSCameraUsageDescription' => 'Beatrax używa aparatu do zeskanowania kodu parowania wyświetlonego na Twoim drugim urządzeniu. Nic nie jest fotografowane ani zapisywane.',
    'NSFaceIDUsageDescription' => 'Beatrax używa Face ID, aby odblokować Twoje finanse i udostępnić klucz, którym zaszyfrowane są Twoje dane.',
    'NSLocalNetworkUsageDescription' => 'Beatrax używa Twojej sieci lokalnej, aby synchronizować Twoje finanse bezpośrednio z Twoimi innymi urządzeniami Beatrax — nic nie opuszcza w tym celu Twojej sieci domowej.',
];
