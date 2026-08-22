<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Vérifier et valider',
    'h1' => 'Vérifie tout ce que nous avons trouvé',

    'lede_counts' => ':transactions provenant de :sources.',
    'source' => ':count source|:count sources',
    'lede_confirm' => 'Confirme tes soldes de départ, puis valide.',

    'empty' => 'Rien à vérifier pour l\'instant. Dépose un relevé aux étapes précédentes pour voir tes transactions ici.',

    'sb_eyebrow_label' => '🧮 SOLDES DE DÉPART ·',
    'account_detected' => ':count COMPTE DÉTECTÉ|:count COMPTES DÉTECTÉS',
    'sb_lede' => 'Nous avons détecté le solde de départ de chaque compte. Confirme-le ou modifie-le avant que nous validions.',

    'txn' => ':count transaction|:count transactions',
    'to_commit' => 'à valider ·',
    'already_imported' => ':count déjà importée|:count déjà importées',
    'commit_committing' => 'Validation…',
    'commit_count' => 'Tout valider (:count transaction) →|Tout valider (:count transactions) →',
    'commit_empty' => 'Tout valider (—) →',
    'skip' => 'Passer pour l\'instant',

    'errors' => [
        'nothing_to_commit' => 'Rien à valider.',
        'commit_failed' => 'Nous n\'avons pas pu valider tes relevés. Rien n\'a été modifié — réessaie.',
    ],

    'section' => [
        'from_prefix' => 'DE ',
        'from_bank' => 'DE TON RELEVÉ BANCAIRE',
        'from_ics' => 'DE TES RELEVÉS DE CARTE ICS',
        'from_paypal' => 'DE PAYPAL',
        'row' => ':count LIGNE|:count LIGNES',
        'badge_ready' => '✓ PRÊT',
        'badge_empty' => 'VIDE',
        'badge_error' => 'À RENVOYER',
        'error_body' => 'Nous n\'avons pas pu lire tous les fichiers de cette source. Essaie un autre fichier →',
        'partial_body' => 'Une partie de ce fichier n\'a pas pu être lue et a été laissée de côté : :reason',
        'empty_body' => 'Ce relevé est vide.',
        'col_date' => 'Date',
        'col_type' => 'Type',
        'col_counterparty' => 'Tiers',
        'col_amount' => 'Montant',
        'load_more' => 'Afficher plus (:remaining restantes)',
        'rows_shown' => ':count ligne affichée|:count lignes affichées',
    ],
];
