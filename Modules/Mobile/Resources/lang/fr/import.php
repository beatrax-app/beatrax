<?php

declare(strict_types=1);

return [
    'page_title' => 'Importer depuis un autre appareil',

    'heading' => 'Importer depuis un autre appareil',
    'subtitle' => 'Configure ce téléphone avec son propre compte et son propre verrouillage, puis appaire-le à ton autre appareil pour récupérer ton historique.',

    'username' => 'Nom d\'utilisateur',
    'password' => 'Mot de passe',
    'password_help' => 'Au moins 12 caractères — il n\'y a pas de réinitialisation du mot de passe, seulement des codes de récupération.',
    'confirm_password' => 'Confirme le mot de passe',
    'pin' => 'PIN de verrouillage de l\'app',
    'pin_help' => '6-10 chiffres — déverrouille cet appareil.',
    'confirm_pin' => 'Confirme le PIN',
    'continue' => 'Continuer',

    'failed_heading' => 'La configuration n\'est pas allée au bout',
    'failed_body' => 'Ton compte a été créé, mais nous n\'avons pas pu terminer la configuration de cet appareil. Tu peux réessayer sans risque.',
    'try_again' => 'Réessayer',

    'recovery_heading' => 'Conserve ces codes de récupération',
    'recovery_body' => 'Imprime-les ou range-les en lieu sûr. Ils ne seront plus affichés.',
    'already_heading' => 'Cet appareil est déjà configuré',
    'already_body' => 'Ton compte existe déjà sur cet appareil. Passe à l\'appairage pour le connecter à tes autres appareils.',
    'recovery_download' => 'Télécharger en .txt',
    'recovery_copy' => 'Copier les codes',
    'recovery_copied' => 'Copié',
    'recovery_copy_failed' => 'Copie impossible. Notez plutôt les codes.',
    'recovery_saved' => 'Enregistré dans tes téléchargements.',
    'recovery_share_title' => 'Codes de récupération Beatrax',
    'recovery_share_message' => 'Conservez-les en lieu sûr.',
    'recovery_save_failed' => 'Impossible d\'enregistrer le fichier. Notez plutôt les codes.',
    'recovery_confirm' => 'J\'ai rangé ces codes en lieu sûr.',
    'continue_to_pairing' => 'Passer à l\'appairage',

    'errors' => [
        'passwords_mismatch' => 'Les mots de passe ne correspondent pas.',
        'password_length' => 'Utilise au moins 12 caractères.',
        'pin_length' => 'Le PIN doit comporter au moins 6 chiffres.',
        'pins_mismatch' => 'Les PIN ne correspondent pas. Réessaie.',
        'session_expired' => 'Ta session a expiré avant la fin de la configuration. Ressaisis ton PIN et ton mot de passe.',
        'retry_failed' => 'La configuration de cet appareil n\'a toujours pas pu aboutir. Réessaie.',
        'account_failed' => 'Impossible de créer le compte.',
    ],
];
