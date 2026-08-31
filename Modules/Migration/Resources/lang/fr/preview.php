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

    'all_clean' => 'Tout est associé proprement — il n\'y a rien à décider ici.',

    'nothing_staged' => "Cet export ne contenait rien à importer — il n'y a rien à confirmer ici.",

    'groups' => [
        'conflict' => 'Demande ta décision',
        'extra' => 'Non importé',
    ],

    'keep_or_take_aria' => 'Garder la version locale ou prendre celle de la source pour :label',
    'keep_local' => 'Garder la version locale',
    'take_source' => 'Prendre la source',

    'footer_note' => 'Cela va créer ou mettre à jour les quantités indiquées ci-dessus dans tes catégories, tes budgets et ton registre.',
    'discard_button' => 'Abandonner l\'import',
    'discard_confirm' => 'Abandonner cet import ? Tout ce qui a été lu dans ton fichier d\'export est supprimé ici, et le récupérer suppose de téléverser et d\'analyser à nouveau le fichier entier. Rien n\'est encore arrivé dans ton registre.',
    'confirm_button' => 'Confirmer l\'import',
];
