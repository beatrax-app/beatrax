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
    'NSCameraUsageDescription' => 'Beatrax använder kameran för att läsa av parkopplingskoden som visas på din andra enhet. Ingenting fotograferas eller sparas.',
    'NSFaceIDUsageDescription' => 'Beatrax använder Face ID för att låsa upp din ekonomi och frigöra nyckeln som dina uppgifter är krypterade med.',
    'NSLocalNetworkUsageDescription' => 'Beatrax använder ditt lokala nätverk för att synkronisera din ekonomi direkt med dina andra Beatrax-enheter — ingenting lämnar ditt hemnätverk för det.',
];
