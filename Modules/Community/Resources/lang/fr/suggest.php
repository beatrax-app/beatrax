<?php

declare(strict_types=1);

return [
    'heading' => 'Suggérer une correspondance',
    'intro' => 'Ouvre GitHub dans ton navigateur pour envoyer la suggestion sous forme de PR brouillon. Ton nom et ton e-mail ne quittent jamais cet appareil.',

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
    'submit' => 'Envoyer comme PR brouillon',

    'toast' => 'Suggestion ouverte dans ton navigateur.',

    'errors' => [
        'pattern_required' => 'Le motif est obligatoire.',
        'name_required' => 'Le nom est obligatoire.',
        'browser_refused' => "Ton navigateur n'a pas pu être ouvert : rien n'a été envoyé et rien n'a quitté cet appareil. Réessaie, ou copie toi-même l'aperçu YAML ci-dessus dans une pull request.",
    ],
];
