<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Appareil appairé',
    'page_title' => 'Appairer un appareil',

    'scan_heading' => 'Appairer cet appareil',
    'scan_subtitle' => 'Dirige la caméra vers le code affiché sur l\'autre appareil.',
    'camera_permission_pending' => 'L\'accès à la caméra est désactivé. Autorise-le pour Beatrax dans les paramètres de ton appareil, puis réessaie.',
    'open_camera' => 'Ouvrir la caméra',
    'opening_camera' => 'En attente de l\'accès à la caméra…',
    'close_camera' => 'Fermer la caméra',
    'viewfinder_aria' => 'Viseur de la caméra — dirige-le vers le code sur ton autre appareil',
    'viewfinder_idle' => 'La caméra est éteinte. Ouvre-la pour scanner le code affiché sur ton autre appareil.',
    'scan_prompt' => 'Scanne le code sur ton autre appareil',
    'enter_code_instead' => 'Saisir le code à la place',

    'enter_heading' => 'Saisis le code',
    'camera_off' => 'L\'accès à la caméra est désactivé. Saisis plutôt le code de l\'autre appareil.',
    'word_code_aria' => 'Saisis le code en mots affiché sur l\'autre appareil',
    'submit_code' => 'Envoyer le code',
    'cancel' => 'Annuler',

    'confirm_heading' => 'Compare ces mots avec l\'autre appareil',
    'safety_words_aria' => 'Mots du numéro de sécurité : :words',
    'confirm_body' => 'Les deux appareils doivent afficher exactement les mêmes mots. S\'ils diffèrent, appuie sur Annuler — une attaque de l\'homme du milieu est peut-être en cours.',
    'awaiting_peer' => 'En attente de la confirmation de l\'autre appareil…',
    'confirm_match' => 'Confirmer — ils correspondent',

    'success_heading' => 'Appareil appairé',
    'success_body' => 'Cet appareil est maintenant approuvé. Tes données se synchroniseront dès que tu te connecteras.',
    'done' => 'Terminé',

    'errors' => [
        'relay_unreachable' => 'Impossible de joindre l\'autre appareil. Vérifie que les deux sont sur le même réseau et que la synchronisation est activée sur l\'ordinateur.',
        'invalid_code' => 'Ce code est invalide ou a expiré. Demande à l\'autre appareil d\'en générer un nouveau.',
        'identity_locked' => 'L\'identité de ton appareil est verrouillée. Déverrouille l\'application et réessaie.',
    ],
];
