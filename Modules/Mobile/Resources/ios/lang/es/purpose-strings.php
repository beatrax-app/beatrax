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
    'NSCameraUsageDescription' => 'Beatrax usa la cámara para escanear el código de vinculación que aparece en tu otro dispositivo. No se fotografía ni se guarda nada.',
    'NSFaceIDUsageDescription' => 'Beatrax usa Face ID para desbloquear tus finanzas y liberar la clave con la que están cifrados tus datos.',
    'NSLocalNetworkUsageDescription' => 'Beatrax usa tu red local para sincronizar tus finanzas directamente con tus otros dispositivos Beatrax; para esto nada sale de tu red doméstica.',
];
