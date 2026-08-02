<?php

declare(strict_types=1);

return [
    'title' => 'Rapporten',
    'page_title' => 'Rapporten · beatrax',
    'saved_report' => 'opgeslagen rapport|opgeslagen rapporten',
    'pinned_count' => 'vastgezet',
    'dismiss' => 'Sluiten',

    'build_new' => 'Een nieuw rapport bouwen',
    'view_mode_aria' => 'Weergavemodus',
    'cards' => 'Kaarten',
    'list' => 'Lijst',

    'empty' => [
        'heading' => 'Nog geen opgeslagen rapporten',
        'body' => 'Bouw er hieronder een en sla het op om het hier te zien.',
        'cta' => 'Bouw je eerste rapport →',
    ],

    'pin' => [
        'pinned_aria' => 'Vastgezet — losmaken van dashboard',
        'pin_aria' => 'Vastzetten — vastzetten op dashboard',
        'pinned_title' => 'Vastgezet',
        'pin_title' => 'Vastzetten op dashboard',
        'pinned_label' => 'Vastgezet',
        'pin_label' => 'Vastzetten',
    ],

    'open' => 'Openen',
    'edit' => 'Bewerken',
    'delete_confirm' => '":name" verwijderen?',
    'delete_report' => 'Rapport verwijderen',
    'cancel' => 'Annuleren',
    'delete' => 'Verwijderen',
    'delete_aria' => ':name verwijderen',

    'col' => [
        'name' => 'Naam',
        'summary' => 'Samenvatting',
        'pinned' => 'Vastgezet',
        'actions' => 'Acties',
    ],

    'flash' => [
        'not_found' => 'Rapport niet gevonden (het is mogelijk in een ander tabblad verwijderd).',
        'deleted' => 'Rapport verwijderd.',
    ],
    'pin_cap' => 'Je kunt maximaal 3 rapporten vastzetten. Maak er een los om dit toe te voegen.',

    'summary' => [
        'metric' => [
            'spend' => 'Uitgaven',
            'income' => 'Inkomsten',
            'net' => 'Netto',
            'net_worth' => 'Nettovermogen',
            'fallback' => 'Bedrag',
        ],
        'dimension' => [
            'category' => 'categorie',
            'time_bucket' => 'tijdvak',
            'counterparty' => 'tegenpartij',
            'account' => 'rekening',
            'fallback' => 'categorie',
        ],
        'period' => [
            'this_month' => 'Deze maand',
            'last_3_months' => 'Afgelopen 3 maanden',
            'last_6_months' => 'Afgelopen 6 maanden',
            'last_12_months' => 'Afgelopen 12 maanden',
            'ytd' => 'Jaar tot nu toe',
            'this_year' => 'Dit jaar',
            'custom' => 'Aangepast bereik',
        ],
        'with_dimension' => ':metric · per :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
