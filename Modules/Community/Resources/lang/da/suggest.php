<?php

declare(strict_types=1);

return [
    'heading' => 'Foreslå en kobling',
    'intro' => 'Åbner GitHub i din browser med forslaget udfyldt. Kun mønsteret, navnet, kategorien og regionen ovenfor følger med — og mønsteret er teksten, præcis som dit kontoudtog skrev den. Dit navn og din e-mailadresse forlader aldrig denne enhed.',

    'pattern' => 'Mønster',
    'name' => 'Forståeligt navn',
    'name_placeholder' => 'f.eks. Albert Heijn',
    'category' => 'Kategori (valgfrit)',
    'category_placeholder' => 'f.eks. Dagligvarer',
    'region' => 'Region',

    'regions' => [
        'other' => 'Andet',
    ],

    'yaml_preview' => 'YAML-forhåndsvisning',

    'cancel' => 'Annullér',
    'submit' => 'Åbn på GitHub',

    'toast' => 'Forslaget er åbnet i din browser.',

    'errors' => [
        'pattern_required' => 'Mønster er påkrævet.',
        'name_required' => 'Navn er påkrævet.',
        'browser_refused' => 'Din browser kunne ikke åbnes, så intet blev sendt, og intet forlod denne enhed. Prøv igen, eller kopiér selv YAML-forhåndsvisningen ovenfor ind i en pull request.',
    ],
];
