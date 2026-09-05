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
    'NSCameraUsageDescription' => 'Beatrax usa la fotocamera per scansionare il codice di abbinamento mostrato sull’altro tuo dispositivo. Non viene fotografato né salvato nulla.',
    'NSFaceIDUsageDescription' => 'Beatrax usa Face ID per sbloccare le tue finanze e rilasciare la chiave con cui i tuoi dati sono cifrati.',
    'NSLocalNetworkUsageDescription' => 'Beatrax usa la tua rete locale per sincronizzare le tue finanze direttamente con i tuoi altri dispositivi Beatrax: per questo nulla esce dalla tua rete domestica.',
];
