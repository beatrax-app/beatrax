<?php

declare(strict_types=1);

return [
    'heading' => 'Proponi una corrispondenza',
    'intro' => 'Apre GitHub nel tuo browser così puoi inviare la proposta come bozza di PR. Il tuo nome e la tua email non lasciano mai questo dispositivo.',

    'pattern' => 'Schema',
    'name' => 'Nome leggibile',
    'name_placeholder' => 'es. Albert Heijn',
    'category' => 'Categoria (facoltativa)',
    'category_placeholder' => 'es. Spesa alimentare',
    'region' => 'Regione',

    'regions' => [
        'other' => 'Altro',
    ],

    'yaml_preview' => 'Anteprima YAML',

    'cancel' => 'Annulla',
    'submit' => 'Invia come bozza di PR',

    'toast' => 'Proposta aperta nel tuo browser.',

    'errors' => [
        'pattern_required' => 'Lo schema è obbligatorio.',
        'name_required' => 'Il nome è obbligatorio.',
        'browser_refused' => "Non è stato possibile aprire il tuo browser, quindi non è stato inviato nulla e nulla ha lasciato questo dispositivo. Riprova, oppure copia tu stesso l'anteprima YAML qui sopra in una pull request.",
    ],
];
