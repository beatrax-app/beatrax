<?php

declare(strict_types=1);

return [
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'saved_report' => ':count lagret rapport|:count lagrede rapporter',
    'pinned_count' => 'festet',
    'dismiss' => 'Lukk',

    'build_new' => 'Bygg en ny rapport',
    'view_mode_aria' => 'Visningsmodus',
    'cards' => 'Kort',
    'list' => 'Liste',

    'empty' => [
        'heading' => 'Ingen lagrede rapporter ennå',
        'body' => 'Bygg en nedenfor og lagre den for å se den her.',
        'cta' => 'Bygg din første rapport →',
    ],

    'pin' => [
        'pinned_aria' => 'Festet — løsne fra oversikten',
        'pin_aria' => 'Fest — fest til oversikten',
        'pinned_title' => 'Festet',
        'pin_title' => 'Fest til oversikten',
        'pinned_label' => 'Festet',
        'pin_label' => 'Fest',
    ],

    'open' => 'Åpne',
    'edit' => 'Rediger',

    'delete_confirm' => 'Slette ":name"?',
    'delete_report' => 'Slett rapporten',
    'cancel' => 'Avbryt',
    'delete' => 'Slett',
    'delete_aria' => 'Slett :name',

    'col' => [
        'name' => 'Navn',
        'summary' => 'Sammendrag',
        'pinned' => 'Festet',
        'actions' => 'Handlinger',
    ],

    'flash' => [
        'not_found' => 'Rapporten ble ikke funnet (den kan ha blitt slettet i en annen fane).',
        'deleted' => 'Rapporten er slettet.',
    ],
    'pin_cap' => 'Du kan feste opptil 3 rapporter. Løsne en for å legge til denne.',

    'summary' => [
        'metric' => [
            'spend' => 'Utgifter',
            'income' => 'Inntekter',
            'net' => 'Netto',
            'net_worth' => 'Nettoformue',
            'fallback' => 'Beløp',
        ],
        'dimension' => [
            'category' => 'kategori',
            'time_bucket' => 'tidsintervall',
            'counterparty' => 'motpart',
            'account' => 'konto',
            'fallback' => 'kategori',
        ],
        'period' => [
            'this_month' => 'Denne måneden',
            'last_3_months' => 'Siste 3 måneder',
            'last_6_months' => 'Siste 6 måneder',
            'last_12_months' => 'Siste 12 måneder',
            'ytd' => 'Hittil i år',
            'this_year' => 'I år',
            'custom' => 'Egendefinert intervall',
        ],
        'with_dimension' => ':metric · etter :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
