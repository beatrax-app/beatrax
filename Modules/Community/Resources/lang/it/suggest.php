<?php

declare(strict_types=1);

return [
    'heading' => 'Proponi una corrispondenza',
    'intro' => "Apre GitHub nel tuo browser con la proposta già compilata. Con essa partono solo lo schema, il nome, la categoria e la regione qui sopra — e lo schema è la descrizione così come l'ha scritta il tuo estratto conto. Il tuo nome e la tua email non lasciano mai questo dispositivo.",

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
    'submit' => 'Apri su GitHub',

    'toast' => 'Proposta aperta nel tuo browser.',

    'errors' => [
        'pattern_required' => 'Lo schema è obbligatorio.',
        'name_required' => 'Il nome è obbligatorio.',
        'browser_refused' => "Non è stato possibile aprire il tuo browser, quindi non è stato inviato nulla e nulla ha lasciato questo dispositivo. Riprova, oppure copia tu stesso l'anteprima YAML qui sopra in una pull request.",
    ],
];
