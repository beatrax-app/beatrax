<?php

declare(strict_types=1);

return [
    'page_title' => 'Aperçu de l\'import',

    'heading' => 'Aperçu de l\'import',
    'subtitle' => 'Vérifie ce qui va changer. Rien n\'est enregistré tant que tu n\'as pas confirmé.',

    'stats' => [
        'category' => 'Catégories',
        'account' => 'Comptes',
        'payee' => 'Tiers',
        'transaction' => 'Transactions',
        'budget' => 'Mois budgétaires',
    ],

    'all_clean' => 'Tout est associé proprement — rien ne demande ton attention avant de confirmer.',

    'groups' => [
        'conflict' => 'Demande ta décision',
        'extra' => 'Non importé',
    ],

    'keep_or_take_aria' => 'Garder la version locale ou prendre celle de la source pour :label',
    'keep_local' => 'Garder la version locale',
    'take_source' => 'Prendre la source',

    'footer_note' => 'Cela va créer ou mettre à jour les quantités indiquées ci-dessus dans tes catégories, tes budgets et ton registre.',
    'discard_button' => 'Abandonner l\'import',
    'confirm_button' => 'Confirmer l\'import',
];
