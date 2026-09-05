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
    'NSCameraUsageDescription' => 'Beatrax gebruikt de camera om de koppelcode te scannen die op je andere apparaat staat. Er wordt niets gefotografeerd of bewaard.',
    'NSFaceIDUsageDescription' => 'Beatrax gebruikt Face ID om je financiën te ontgrendelen en de sleutel vrij te geven waarmee je gegevens versleuteld zijn.',
    'NSLocalNetworkUsageDescription' => 'Beatrax gebruikt je lokale netwerk om je financiën rechtstreeks met je andere Beatrax-apparaten te synchroniseren — hiervoor verlaat er niets je thuisnetwerk.',
];
