<?php

declare(strict_types=1);

return [
    'heading' => 'Sugerează o mapare',
    'intro' => 'Deschide GitHub în browser cu sugestia deja completată. Merg cu ea doar tiparul, numele, categoria și regiunea de mai sus — iar tiparul este descrierea așa cum a scris-o extrasul tău. Numele și adresa ta de e-mail nu părăsesc niciodată acest dispozitiv.',

    'pattern' => 'Tipar',
    'name' => 'Nume prietenos',
    'name_placeholder' => 'ex. Albert Heijn',
    'category' => 'Categorie (opțional)',
    'category_placeholder' => 'ex. Alimente',
    'region' => 'Regiune',

    'regions' => [
        'other' => 'Altele',
    ],

    'yaml_preview' => 'Previzualizare YAML',

    'cancel' => 'Anulează',
    'submit' => 'Deschide pe GitHub',

    'toast' => 'Sugestia s-a deschis în browser.',

    'errors' => [
        'pattern_required' => 'Tiparul este obligatoriu.',
        'name_required' => 'Numele este obligatoriu.',
        'browser_refused' => 'Navigatorul nu a putut fi deschis, așa că nu s-a trimis nimic și nimic nu a părăsit acest dispozitiv. Încearcă din nou sau copiază singur previzualizarea YAML de mai sus într-un pull request.',
    ],
];
