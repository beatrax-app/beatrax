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
    'NSCameraUsageDescription' => 'Beatrax používá fotoaparát ke skenování párovacího kódu zobrazeného na tvém druhém zařízení. Nic se nefotí ani neukládá.',
    'NSFaceIDUsageDescription' => 'Beatrax používá Face ID, aby odemkl tvoje finance a uvolnil klíč, kterým jsou tvoje data zašifrovaná.',
    'NSLocalNetworkUsageDescription' => 'Beatrax používá tvoji místní síť, aby synchronizoval tvoje finance přímo s tvými dalšími zařízeními s Beatraxem — kvůli tomu nic neopouští tvoji domácí síť.',
];
