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
    'NSCameraUsageDescription' => 'O Beatrax usa a câmara para ler o código de emparelhamento mostrado no teu outro dispositivo. Nada é fotografado nem guardado.',
    'NSFaceIDUsageDescription' => 'O Beatrax usa o Face ID para desbloquear as tuas finanças e libertar a chave com que os teus dados estão cifrados.',
    'NSLocalNetworkUsageDescription' => 'O Beatrax usa a tua rede local para sincronizar as tuas finanças diretamente com os teus outros dispositivos Beatrax — nada sai da tua rede doméstica para isso.',
];
