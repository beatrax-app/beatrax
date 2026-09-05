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
    'NSCameraUsageDescription' => '„Beatrax“ naudoja kamerą, kad nuskaitytų susiejimo kodą, rodomą kitame tavo įrenginyje. Niekas nefotografuojama ir neįrašoma.',
    'NSFaceIDUsageDescription' => '„Beatrax“ naudoja „Face ID“, kad atrakintų tavo finansus ir atlaisvintų raktą, kuriuo užšifruoti tavo duomenys.',
    'NSLocalNetworkUsageDescription' => '„Beatrax“ naudoja tavo vietinį tinklą, kad tavo finansus sinchronizuotų tiesiai su kitais tavo „Beatrax“ įrenginiais — tam niekas neišeina iš tavo namų tinklo.',
];
