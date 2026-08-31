<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ta carte de crédit',
    'h1' => 'Récupère les PDF de tes relevés mensuels',
    'lede' => 'Dépose tous tes relevés mensuels en PDF — nous les regroupons en un seul aperçu.',

    'format_group_aria' => 'ICS exporte uniquement en PDF',
    'issuer_note' => 'ICS est pour l’instant le seul émetteur de cartes que nous savons lire, et uniquement son relevé en néerlandais. Si ta carte vient d’un autre émetteur, passe cette étape.',
    'got_it_as' => 'Reçu au format :',
    'badge_only_format' => 'seul format',

    'mini' => [
        'login_label' => 'Connecte-toi',
        'statements_label' => 'Ouvre les relevés',
        'months_label' => 'Choisis les mois',
        'months_sub' => 'Un PDF par mois',
        'download_label' => 'Télécharge',
    ],

    'drop_lead' => 'Dépose tes PDF ICS ici',
    'browse_files' => 'ou parcours tes fichiers',
    'queue_aria' => 'Relevés PDF en attente',

    'skip' => 'Passer cette étape',
    'continue' => 'Continuer →',

    'errors' => [
        'required' => 'Dépose les relevés mensuels en PDF que tu as téléchargés depuis Mijn ICS.',
        'min' => 'Dépose au moins un relevé ICS en PDF avant de continuer.',
        'each_required' => 'Dépose le relevé mensuel en PDF que tu as téléchargé depuis Mijn ICS.',
        'each_max' => 'Un de tes fichiers est trop volumineux. Les relevés ICS en PDF font normalement moins de 1 Mo chacun.',
        'each_extensions' => 'Un de tes fichiers n\'est pas un PDF. Mijn ICS n\'exporte qu\'en PDF — essaie le dernier relevé mensuel.',
        'file_unreadable' => 'Impossible de lire :filename. L\'erreur complète est dans /dev/logs.',
        'none_readable' => 'Nous n\'avons pu lire aucun de tes PDF ICS. :detail',
        'full_error_in_logs' => 'L\'erreur complète est dans /dev/logs.',
    ],
];
