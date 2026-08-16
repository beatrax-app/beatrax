<?php

declare(strict_types=1);

return [
    'heading_named' => 'Catena per :name',
    'heading' => 'Catena',

    'unresolved_heading' => 'Catena non ancora risolta',
    'unresolved_body' => 'Il risolutore delle catene è ancora in esecuzione. Apri la coda di revisione oppure aggiorna tra un momento.',

    'none_heading' => 'Nessuna catena di finanziamento trovata',
    'none_body' => 'Per questa transazione non è stata rilevata nessuna catena di finanziamento. Se te ne aspettavi una, proponi un candidato dalla coda di revisione.',

    'none_beyond_leg' => 'Nessuna catena di finanziamento trovata oltre questo passaggio.',

    'covers_charges' => 'Copre :count addebiti ICS',
    'no_ics_charges' => 'Nessun addebito ICS in questo regolamento',
    'show_more_fanout' => 'Mostra altri :count · :shown di :total',

    'confirm' => 'Conferma',
    'reject' => 'Rifiuta',
    'confirm_aria' => "Conferma l'anello della catena :id",
    'reject_aria' => "Rifiuta l'anello della catena :id",

    'confidence_aria' => [
        'deterministic' => 'Affidabilità: corrispondenza deterministica',
        'confirmed' => 'Affidabilità: confermata',
        'candidate' => 'Affidabilità: candidato; richiede revisione',
    ],
];
