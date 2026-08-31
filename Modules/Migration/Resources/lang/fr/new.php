<?php

declare(strict_types=1);

return [
    'page_title' => 'Importer depuis YNAB / Actual',

    'eyebrow' => 'Migrations',
    'heading' => 'Importer depuis YNAB / Actual',
    'intro' => 'Récupère ton arborescence de catégories, ton historique de budget et tes transactions depuis YNAB4, le nouveau YNAB ou Actual Budget. Rien n\'est écrit dans ton registre tant que tu n\'as pas vérifié et confirmé.',
    'reconcile_context' => 'Recherche de nouveautés par rapport à ton dernier import :product.',

    'source_label' => 'Source',
    'file_label' => 'Fichier',
    'parse_button' => 'Analyser l\'export',

    'hints' => [
        'ynab4' => 'Exporte l\'intégralité de ton budget au format ZIP depuis le menu File → Export de YNAB4.',
        'nynab' => 'Exporte ton budget depuis nYNAB via File → Export Budget, puis compresse les fichiers CSV exportés dans un ZIP.',
        'actual' => 'Exporte ton budget au format ZIP depuis Settings → Export data d\'Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Ce fichier ne ressemble pas à un export YNAB4, nYNAB ou Actual que nous savons lire. Vérifie le fichier et réessaie.',
        'file_too_large' => 'Ce fichier est trop volumineux pour un export de migration.',
        'archive_reader_unavailable' => "Cette version de l'app n'a aucun lecteur ZIP capable d'ouvrir cet export, il ne peut donc pas être lu ici. Importe-le plutôt dans l'app de bureau, ou recompresse l'export avec une compression ordinaire.",
        'internal_detail' => "L'application n'a pas pu lire cet export (:code). Les détails complets figurent dans le journal ; cite ce code si tu signales un problème.",
    ],
];
