<?php

declare(strict_types=1);

return [
    'title' => 'Appairer un nouvel appareil',
    'step_1_of_3' => 'Étape 1 sur 3',
    'step_2_of_3' => 'Étape 2 sur 3',
    'step_3_of_3' => 'Étape 3 sur 3',

    'show_my_code' => 'Afficher mon code',
    'show_my_code_help' => "Affiche le code de cet appareil pour que l'autre le lise.",
    'enter_a_code' => 'Saisir un code',
    'safety_number_changed' => 'L\'autre appareil a changé pendant la comparaison. Vérifie à nouveau les mots ci-dessous avant de confirmer.',
    'enter_a_code_help' => 'Tape le code affiché sur l\'autre appareil.',

    'show_this_code' => 'Afficher ce code',
    'enter_on_other' => 'Saisis ce code sur l\'autre appareil, ou laisse-le scanner le QR.',
    'scan_on_other' => "Scanne ce code avec l'appareil photo de l'autre appareil. Un ordinateur n'a pas d'appareil photo : affiche son code et saisis-le ici.",
    'expires_in' => 'Expire dans',
    'code_expired' => 'Code expiré.',
    'generate_new_code' => 'Générer un nouveau code',
    'cancel_pairing' => 'Annuler l\'appairage',

    'enter_the_code' => 'Saisis le code',
    'enter_code_aria' => 'Saisis le code en mots affiché sur l\'autre appareil',
    'submit_code' => 'Envoyer le code',

    'compare_words' => 'Compare ces mots avec l\'autre appareil',
    'safety_number_words' => 'Mots du numéro de sécurité :',
    'compare_help' => 'Les deux appareils doivent afficher exactement les mêmes mots. S\'ils diffèrent, touche Annuler l\'appairage — une attaque de l\'homme du milieu est peut-être en cours.',
    'waiting_for_peer' => 'En attente de la confirmation de l\'autre appareil…',
    'confirm_match' => 'Confirmer — ils correspondent',

    'device_paired' => 'Appareil appairé',
    'device_paired_help' => 'Cet appareil est maintenant de confiance. Tes données se synchroniseront dès que tu te connecteras.',
    'done' => 'Terminé',

    'identity_locked' => 'L\'identité de ton appareil est verrouillée. Déverrouille l\'app et réessaie.',
    'invalid_code' => 'Ce code est invalide ou a expiré. Demande à l\'autre appareil d\'en générer un nouveau.',
    'code_incomplete' => 'Ce code n\'est pas complet. Compare-le avec l\'autre appareil et saisis-le en entier.',
    'code_not_accepted' => "Aucun appareil de ce réseau n'a accepté ce code. Vérifie le code et que l'autre appareil l'affiche toujours.",
    'no_peer_answered' => "Rien sur ce réseau n'a répondu à ce code. Vérifie que la synchronisation tourne sur l'autre appareil.",
    'no_peer_search' => "Cet appareil n'a pas pu chercher sur le réseau, il n'a donc pas pu vérifier ce code. Affiche plutôt le code de cet appareil et saisis-le sur l'autre.",
    'rate_limited' => 'Trop de tentatives. Attends une minute et réessaie.',
];
