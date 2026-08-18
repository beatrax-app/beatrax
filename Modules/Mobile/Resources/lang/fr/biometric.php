<?php

declare(strict_types=1);

return [
    'heading' => 'Déverrouillage biométrique',
    'unavailable' => 'Le déverrouillage biométrique n\'est disponible que dans l\'application mobile Beatrax.',
    'toggle_label' => 'Déverrouiller avec Face ID / Touch ID',
    'toggle_help' => 'Déverrouille l\'application après un redémarrage avec la biométrie plutôt qu\'avec ton PIN. Le PIN reste demandé régulièrement et à chaque changement de tes données biométriques.',
    'toggle_aria' => 'Déverrouiller avec la biométrie',
    'confirm_pin_heading' => 'Confirme ton PIN pour activer',
    'current_pin' => 'PIN actuel',
    'enable' => 'Activer le déverrouillage biométrique',

    'errors' => [
        'unavailable' => 'Le déverrouillage biométrique n\'est pas disponible sur cet appareil.',
        'pin_required' => 'Saisis ton PIN (6 à 10 chiffres) pour activer le déverrouillage biométrique.',
        'enroll_failed' => 'Impossible d\'activer le déverrouillage biométrique — vérifie ton PIN et réessaie.',
    ],
];
