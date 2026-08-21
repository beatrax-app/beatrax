<?php

declare(strict_types=1);

return [
    'heading' => 'Appareils et synchronisation',

    'enable_sync' => 'Activer la synchronisation',
    'enable_sync_help' => 'Partage tes données en toute sécurité entre tes appareils de confiance. Nécessite un verrouillage de l\'app.',

    'app_lock_notice' => 'Configure d\'abord un verrouillage de l\'app pour activer la synchronisation.',
    'go_to_app_lock' => 'Aller au verrouillage de l\'app',

    'encrypted_at_rest' => 'Données chiffrées au repos',
    'encrypted_at_rest_scope' => 'Tes notes, les libellés de transaction et les noms et IBAN de tes bénéficiaires sont chiffrés dans le registre avec la phrase secrète de verrouillage de l\'app. Les montants, les dates et le nom et l\'IBAN de ton propre compte ne le sont pas. L\'index de recherche conserve sa propre copie lisible de qui tu paies, de tes libellés de transaction et de tes notes fiscales, et certains noms de commerçants restent en clair ailleurs dans le fichier de base de données.',
    'on' => 'Activé',
    'securing' => 'Sécurisation de tes données…',
    'do_not_close' => 'Ne ferme pas cette fenêtre.',
    'encryption_progress_aria' => 'Progression du chiffrement',
    'not_encrypted_offer' => 'Vos données ne sont pas chiffrées au repos. Le chiffrement masque qui vous payez si cet appareil est perdu ou volé — les montants, les dates et l\'index de recherche restent lisibles.',
    'enable_encryption' => 'Activer le chiffrement',

    'your_devices' => 'Tes appareils',

    'moved_help' => 'L\'appairage, les noms d\'appareils et le chiffrement se trouvent désormais avec ton état de synchronisation.',
    'moved_cta' => 'Ouvrir Synchronisation et appareil',
    'device_name' => 'Nom de l\'appareil',
    'save' => 'Enregistrer',
    'peer_default_name' => 'Appareil appairé',
    'rename_device' => 'Renommer l\'appareil',
    'this_device' => 'Cet appareil',
    'removed' => 'Supprimé',
    'confirmed' => 'Confirmé',
    'awaiting_confirmation' => 'En attente de confirmation',
    'safety_number_words' => 'Mots du numéro de sécurité :',
    'paired' => 'Appairé',
    'remove_aria' => 'Supprimer :name',
    'remove' => 'Supprimer',
    'pair_new_device' => 'Appairer un nouvel appareil',

    'relay_endpoint' => 'Point de terminaison du relais',
    'relay_endpoint_help' => 'Facultatif. Une fois défini, les appareils hors ligne se synchronisent via ce relais. Laisse vide pour du LAN&#8209;direct uniquement.',
    'relay_endpoint_aria' => 'URL du point de terminaison du relais',
    'relay_insecure_warning' => 'Ce point de terminaison de relais utilise du HTTP simple. Même si le relais ne déchiffre jamais tes données, une connexion non sécurisée expose la taille des paquets chiffrés et leur horodatage aux observateurs du réseau. Utilise un point de terminaison <strong>https://</strong> pour une confidentialité optimale.',

    'enable_at_rest' => 'Activer le chiffrement au repos',
    'enable_at_rest_body' => 'Tes données seront chiffrées avec la phrase secrète de verrouillage de l\'app. Une sauvegarde préalable à la migration sera créée automatiquement.',
    'no_recovery_warning' => 'Si tu perds ta phrase secrète de verrouillage et que tu n\'as ni sauvegarde ni autre appareil de confiance, tes données seront irrécupérables.',
    'recover_help' => 'Pour retrouver l\'accès, réappaire cet appareil depuis un autre appareil de confiance, ou utilise ta sauvegarde chiffrée indépendante.',
    'amounts_plaintext' => 'Les montants ne sont pas chiffrés au repos — les soldes et les totaux restent lisibles pour que tes totaux mensuels continuent de tomber juste.',
    'search_plaintext' => 'L\'index de recherche conserve une copie en clair du nom du commerçant et de la description pour que la recherche en texte intégral continue de fonctionner.',
    'keep_unencrypted' => 'Garder les données non chiffrées',
    'encryption_enabled' => 'Chiffrement activé',
    'encryption_enabled_scope' => 'Tes notes, tes libellés et tes bénéficiaires sont désormais chiffrés avec la phrase secrète de verrouillage de l\'app. Les montants, les dates et l\'index de recherche restent lisibles.',
    'done_encryption_enabled' => 'Terminé — chiffrement activé',
    'encryption_failed' => 'Échec de la configuration du chiffrement',
    'encryption_failed_body' => 'Tes données n\'ont pas été modifiées. Ta sauvegarde a été conservée.',
    'close_no_changes' => 'Fermer — aucune modification',

    'remove_this_device' => 'Supprimer cet appareil',
    'removing' => 'Suppression :',
    'remove_rotates_key' => 'Supprimer cet appareil renouvelle la clé de chiffrement, si bien qu\'il ne reçoit plus aucune mise à jour.',
    'remove_cannot_erase' => 'Cela n\'efface pas les données déjà présentes sur cet appareil. S\'il a été perdu ou volé, considère comme exposées toutes les données qu\'il contenait.',
    'remove_device' => 'Supprimer l\'appareil',
    'keep_device' => 'Garder l\'appareil',
    'rotating_key' => 'Renouvellement de la clé de chiffrement…',

    'flash' => [
        'app_lock_first' => 'Configure d\'abord un verrouillage de l\'app pour activer la synchronisation.',
        'enable_failed' => 'Impossible d\'activer la synchronisation. Vérifie que ton verrouillage de l\'app est actif et réessaie.',
        'cannot_remove_self' => 'Tu ne peux pas supprimer cet appareil — c\'est celui que tu utilises.',
        'remove_failed' => 'Impossible de supprimer l\'appareil. Réessaie.',
        'app_lock_first_settings' => 'Configure d\'abord un verrouillage de l\'app pour changer les paramètres de synchronisation.',
        'relay_cleared' => 'Point de terminaison du relais effacé.',
        'relay_saved' => 'Point de terminaison du relais enregistré.',
        'relay_save_failed' => 'Impossible d\'enregistrer le point de terminaison du relais : :message',
    ],
];
