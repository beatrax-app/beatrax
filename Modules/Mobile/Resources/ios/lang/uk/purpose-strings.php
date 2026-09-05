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
    'NSCameraUsageDescription' => 'Beatrax використовує камеру, щоб відсканувати код сполучення, показаний на твоєму іншому пристрої. Нічого не фотографується й не зберігається.',
    'NSFaceIDUsageDescription' => 'Beatrax використовує Face ID, щоб розблокувати твої фінанси й вивільнити ключ, яким зашифровані твої дані.',
    'NSLocalNetworkUsageDescription' => 'Beatrax використовує твою локальну мережу, щоб синхронізувати твої фінанси напряму з іншими твоїми пристроями Beatrax — для цього нічого не залишає твою домашню мережу.',
];
