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
    'camera_off_no_search' => 'L’accès à la caméra est désactivé, et chercher l’autre appareil sur le réseau ne fonctionne pas encore sur iPhone — un code saisi n’a donc rien pour le trouver. Réactive l’accès à la caméra pour Beatrax dans les paramètres de ton appareil, puis scanne le code affiché sur l’autre appareil.',
    'no_search' => 'Chercher l’autre appareil sur le réseau ne fonctionne pas encore sur iPhone, donc un code saisi n’a rien à trouver. Scanne plutôt le code avec la caméra — la caméra n’a pas besoin de chercher sur le réseau.',
    'word_code_aria' => 'Saisis le code en mots affiché sur l\'autre appareil',
    'submit_code' => 'Envoyer le code',
    'cancel' => 'Annuler',
    'skip_import' => 'Continuer sans importer',

    'confirm_heading' => 'Compare ces mots avec l\'autre appareil',
    'safety_words_aria' => 'Mots du numéro de sécurité : :words',
    'confirm_body' => 'Les deux appareils doivent afficher exactement les mêmes mots. S\'ils diffèrent, appuie sur Annuler — une attaque de l\'homme du milieu est peut-être en cours.',
    'awaiting_peer' => 'En attente de la confirmation de l\'autre appareil…',
    'confirm_match' => 'Confirmer — ils correspondent',

    'success_heading' => 'Appareil appairé',
    'success_body' => 'Cet appareil est maintenant approuvé. Tes données se synchroniseront dès que tu te connecteras.',
    'encryption_incomplete' => 'Cet appareil est appairé, mais le chiffrement des données qui y sont stockées n\'a pas abouti. Elles ne sont pas encore stockées chiffrées.',
    'done' => 'Terminé',

    'errors' => [
        'relay_unreachable' => 'Impossible de joindre l\'autre appareil. Vérifie que les deux sont sur le même réseau et que la synchronisation est activée sur l\'ordinateur.',
        'no_road_home' => 'Cet appareil ne peut pas parcourir le réseau, et le code scanné ne contient aucune adresse pour joindre l\'autre appareil. Demande-lui d\'afficher un nouveau code, puis scanne celui-là.',
        'invalid_code' => 'Ce code est invalide ou a expiré. Demande à l\'autre appareil d\'en générer un nouveau.',
        'already_under_way' => 'Cet appareil a déjà accepté ce code et attend la confirmation de l\'autre appareil. Si elle ne vient pas, demande un nouveau code et utilise celui-là.',
        'vouched_but_refused' => 'L\'autre appareil a toujours ce code, mais cet appareil n\'a pas pu l\'accepter. Demande-lui un nouveau code et utilise celui-là.',
        'code_incomplete' => 'Ce code n\'est pas complet. Compare-le avec l\'autre appareil et saisis-le en entier.',
        'code_not_accepted' => 'Aucun appareil de ce réseau n’a accepté ce code. Vérifie le code et que l’autre appareil l’affiche toujours.',
        'no_peer_answered' => 'Rien sur ce réseau n’a répondu à ce code. Vérifie que la synchronisation tourne sur l’autre appareil, ou scanne son code avec l’appareil photo — celui-ci n’a pas besoin de chercher sur le réseau.',
        'no_peer_answered_ios' => 'Rien sur ce réseau n’a répondu à ce code. Chercher l’autre appareil sur le réseau ne fonctionne pas encore sur iPhone : scanne plutôt son code avec l’appareil photo.',
        'no_peer_answered_camera_off' => 'Rien sur ce réseau n’a répondu à ce code. Chercher l’autre appareil sur le réseau ne fonctionne pas encore sur iPhone, et l’accès à la caméra est désactivé : réactive l’accès à la caméra pour Beatrax dans les paramètres de ton appareil, puis scanne le code affiché sur l’autre appareil.',
        'rate_limited' => 'Trop de tentatives. Attends une minute et réessaie.',
        'identity_locked' => 'L\'identité de ton appareil est verrouillée. Déverrouille l\'application et réessaie.',
        'identity_needs_lock' => 'Configurez d\'abord le verrouillage de l\'application — il protège l\'identité de votre appareil.',
        'safety_number_changed' => 'L\'autre appareil a changé pendant la comparaison. Vérifie à nouveau les mots ci-dessous avant de confirmer.',
    ],
];
