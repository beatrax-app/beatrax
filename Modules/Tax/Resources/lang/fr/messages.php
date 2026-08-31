<?php

declare(strict_types=1);

return [

    'reconciled_lock' => 'Cette transaction est rapprochée. Annule le rapprochement pour la modifier.',
    'tagged' => 'Marquée comme déductible fiscalement.',
    'untagged' => 'Marquage fiscal retiré.',
    'batch_none_reconciled' => 'Rien n\'a été marqué — ces transactions sont rapprochées. Annule leur rapprochement pour les modifier.',
    'batch_tagged' => ':count transaction supplémentaire marquée.|:count transactions supplémentaires marquées.',

    'errors' => [
        'name_empty' => 'Le nom de la catégorie ne peut pas être vide.',
        'name_duplicate' => 'Une catégorie porte déjà ce nom.',
        'category_not_saved' => 'Cette catégorie n\'a pas pu être enregistrée. Réessayez.',
        'tag_refused' => 'Cette étiquette n\'a pas pu être enregistrée. Fermez le sélecteur et réessayez.',
    ],
];
