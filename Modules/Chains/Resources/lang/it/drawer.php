<?php

declare(strict_types=1);

return [
    'heading_named' => 'Catena per :name',
    'heading' => 'Catena',

    'unresolved_heading' => 'Nessuna transazione selezionata',
    'unresolved_body' => "Scegli una riga nella lista delle transazioni per vedere che cosa l'ha pagata.",

    'none_heading' => 'Nessuna catena di finanziamento trovata',
    'none_body' => 'Per questa transazione non è stata rilevata nessuna catena di finanziamento. Se te ne aspettavi una, proponi un candidato dalla coda di revisione.',

    'none_beyond_leg' => 'Nessuna catena di finanziamento trovata oltre questo passaggio.',

    'covers_charges' => 'Copre :count addebito ICS|Copre :count addebiti ICS',
    'show_more_fanout' => 'Mostra altri :count · :shown di :total',

    'confirm' => 'Conferma',
    'reject' => 'Rifiuta',
    'confirm_aria' => "Conferma l'anello della catena :id",
    'reject_aria' => "Rifiuta l'anello della catena :id",

    'confidence_tier' => [
        'deterministic' => 'Deterministica',
        'confirmed' => 'Confermata',
        'candidate' => 'Candidato',
    ],

    'confidence_aria' => [
        'deterministic' => 'Affidabilità: corrispondenza deterministica',
        'confirmed' => 'Affidabilità: confermata',
        'candidate' => 'Affidabilità: candidato; richiede revisione',
    ],
];
