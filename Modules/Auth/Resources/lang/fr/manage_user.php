<?php

declare(strict_types=1);

return [
    'page_title' => 'Gérer :name · Beatrax',
    'heading' => 'Gérer :name',
    'subtitle' => 'Consulte, réinitialise ou régénère les codes de cet utilisateur.',

    'set_password' => [
        'heading' => 'Définir un nouveau mot de passe pour cet utilisateur',
        'description' => 'À sa prochaine connexion, cette personne devra choisir un mot de passe.',
        'open' => 'Définir un nouveau mot de passe pour cet utilisateur',
        'body' => 'Définis un nouveau mot de passe pour :name. À sa prochaine connexion, cette personne devra choisir un mot de passe.',
        'label' => 'Nouveau mot de passe',
        'submit' => 'Définir le mot de passe',
        'cancel' => 'Annuler',
    ],

    'regenerate' => [
        'heading' => 'Régénérer les codes de récupération de cet utilisateur',
        'description' => 'Les anciens codes seront invalidés.',
        'open' => 'Régénérer les codes de récupération de cet utilisateur',
        'body' => 'Ses codes existants non utilisés cesseront de fonctionner. Tu verras les 10 nouveaux codes une seule fois et tu pourras les lui transmettre.',
        'confirm_label' => 'Saisis le nom d\'utilisateur pour continuer',
        'submit' => 'Régénérer les codes',
        'keep' => 'Garder les codes actuels',
        'download' => 'Télécharger en .txt',
    ],

    'error_min_length' => 'Utilise au moins 12 caractères.',
    'password_set' => 'Mot de passe défini pour :name. À sa prochaine connexion, cette personne devra choisir un mot de passe.',
    'codes_regenerated' => 'Dix nouveaux codes de récupération générés pour :name.',
];
