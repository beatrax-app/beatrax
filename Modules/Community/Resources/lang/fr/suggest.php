<?php

declare(strict_types=1);

return [
    'heading' => 'Suggérer une correspondance',
    'intro' => "Ouvre GitHub dans ton navigateur avec la suggestion déjà remplie. Seuls le motif, le nom, la catégorie et la région ci-dessus partent avec elle — et le motif est la description telle que ton relevé l'a écrite. Ton nom et ton e-mail ne quittent jamais cet appareil.",

    'pattern' => 'Motif',
    'name' => 'Nom lisible',
    'name_placeholder' => 'ex. Albert Heijn',
    'category' => 'Catégorie (facultatif)',
    'category_placeholder' => 'ex. Courses',
    'region' => 'Région',

    'regions' => [
        'other' => 'Autre',
    ],

    'yaml_preview' => 'Aperçu YAML',

    'cancel' => 'Annuler',
    'submit' => 'Ouvrir sur GitHub',

    'toast' => 'Suggestion ouverte dans ton navigateur.',

    'errors' => [
        'pattern_required' => 'Le motif est obligatoire.',
        'name_required' => 'Le nom est obligatoire.',
        'browser_refused' => "Ton navigateur n'a pas pu être ouvert : rien n'a été envoyé et rien n'a quitté cet appareil. Réessaie, ou copie toi-même l'aperçu YAML ci-dessus dans une pull request.",
    ],
];
