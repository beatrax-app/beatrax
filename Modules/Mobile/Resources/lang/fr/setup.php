<?php

declare(strict_types=1);

return [
    'blocked' => [
        'no_peer' => 'En attente de la confirmation de l\'autre appareil.',
        'no_keys' => 'En attente des clés de chiffrement de l\'autre appareil.',
        'unreachable' => 'Impossible de joindre l\'autre appareil — vérifie que les deux sont sur le même réseau.',
        'reprojecting' => 'Reconstruction de ton historique…',
        'retrying' => 'Reconnexion à l\'autre appareil…',
        'locked' => 'Déverrouille l\'app pour poursuivre la configuration.',
        'revoked' => 'Cet appareil a été retiré depuis votre autre appareil. Associez-le à nouveau pour reprendre la synchronisation.',
    ],
    'step' => [
        'connect' => 'Connexion à ton autre appareil',
        'keys' => 'Réception des clés de chiffrement',
        'transfer' => 'Transfert de ton historique',
        'rebuild' => 'Reconstruction de ton historique',
    ],
    'step_current' => 'étape en cours',
    'working' => [
        'connect' => 'Recherche de ton autre appareil…',
        'keys' => 'Déverrouillage de tes données…',
        'transfer' => 'Demande de ton historique…',
        'rebuild' => 'Reconstruction de ton historique — ça peut prendre une minute.',
    ],
    'page_title' => 'Configuration…',
    'resuming' => 'Reprise de la configuration…',
    'setting_up' => 'Configuration de cet appareil…',
    'progress_aria' => 'Progression de la configuration',
    'records' => ':count enregistrements',
    'records_preparing' => 'En attente de l\'autre appareil…',
];
