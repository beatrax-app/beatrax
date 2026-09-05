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
    'NSCameraUsageDescription' => 'Beatrax, diğer cihazında görünen eşleştirme kodunu taramak için kamerayı kullanır. Hiçbir şey fotoğraflanmaz veya saklanmaz.',
    'NSFaceIDUsageDescription' => 'Beatrax, finanslarının kilidini açmak ve verilerinin şifrelendiği anahtarı serbest bırakmak için Face ID’yi kullanır.',
    'NSLocalNetworkUsageDescription' => 'Beatrax, finanslarını doğrudan diğer Beatrax cihazlarınla eşitlemek için yerel ağını kullanır; bunun için hiçbir şey ev ağından çıkmaz.',
];
