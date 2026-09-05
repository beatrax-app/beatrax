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
    'NSCameraUsageDescription' => 'Beatrax използва камерата, за да сканира кода за сдвояване, показан на другото ти устройство. Нищо не се снима и не се запазва.',
    'NSFaceIDUsageDescription' => 'Beatrax използва Face ID, за да отключи финансите ти и да освободи ключа, с който данните ти са шифровани.',
    'NSLocalNetworkUsageDescription' => 'Beatrax използва локалната ти мрежа, за да синхронизира финансите ти директно с другите ти устройства с Beatrax — за това нищо не напуска домашната ти мрежа.',
];
