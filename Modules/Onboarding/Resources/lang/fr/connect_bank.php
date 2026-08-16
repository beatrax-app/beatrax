<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ta banque',
    'h1' => 'Récupère un relevé, puis dépose-le ci-dessous',
    'lede' => 'Choisis le format que ta banque t\'a donné, puis dépose le fichier. Nous détectons automatiquement CAMT.053 et MT940.',

    'format_group_aria' => 'Format du relevé bancaire',
    'got_it_as' => 'Reçu au format :',
    'badge_recommended' => 'recommandé',

    'mini' => [
        'login_label' => 'Connecte-toi',
        'login_sub' => 'Le site de ta banque',
        'statements_label' => 'Ouvre les relevés',
        'statements_sub' => 'Dans le menu de ta banque',
        'range_label' => 'Choisis une période',
        'range_sub' => 'Les 90 derniers jours',
        'download_label' => 'Télécharge',
    ],

    'csv_picker_aria' => 'Quelle banque a exporté ton CSV ?',
    'csv_picker_from' => 'De :',

    'drop_lead_camt053' => 'Dépose ton fichier CAMT.053 ici',
    'drop_lead_mt940' => 'Dépose ton fichier MT940 ici',
    'drop_lead_asn' => 'Dépose ton CSV ASN ici',
    'drop_lead_ing' => 'Dépose ton CSV ING ici',
    'drop_lead_pick_bank' => 'Choisis la banque qui a exporté ton CSV — nous devons le savoir pour le lire correctement.',
    'drop_lead_default' => 'Dépose ton fichier de relevé ici',
    'browse_file' => 'ou parcours tes fichiers',

    'banks_mt940' => 'Pris en charge : ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Pris en charge : ASN, ING — d\'autres formats arriveront à mesure que les utilisateurs partagent des exemples.',
    'banks_default' => 'Pris en charge : ASN, ING',

    'file_ready' => '· ✓ prêt',

    'skip' => 'Passer cette étape',
    'continue' => 'Continuer →',

    'errors' => [
        'file_required' => 'Dépose d\'abord ton fichier de relevé dans la zone.',
        'file_max' => 'Ce fichier est trop volumineux. Dépose un relevé de moins de 10 Mo.',
        'file_extensions' => 'Ce fichier ne ressemble pas à un relevé bancaire. Dépose un fichier XML CAMT.053, CSV ou MT940.',
        'pick_bank' => 'Choisis la banque qui a exporté ton CSV avant de continuer.',
        'unreadable' => 'Impossible de lire ce fichier. L\'erreur complète est dans /dev/logs.',
    ],
];
