<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Vrsta nasprotne stranke: :type',
        'merchant' => 'Trgovec',
        'personal' => 'Zasebna oseba',
        'bank' => 'Banka',
        'government' => 'Državna ustanova',
        'self' => 'Lastni račun',
        'unknown' => 'Neznano',
    ],

    'filter_chips' => [
        'aria' => 'Filtriraj po vrsti',
        'all' => 'Vse',
        'merchant' => 'Trgovci',
        'personal' => 'Zasebne osebe',
        'bank' => 'Banke',
        'government' => 'Državne ustanove',
        'self' => 'Lastni računi',
        'unknown' => 'Neznani',
    ],

    'default_name' => [
        'bank_fee' => 'Bančna provizija',
    ],

    'cp_card' => [
        'aria' => 'Nasprotna stranka: :name',
        'recent_aria' => 'Nedavna dejavnost',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Veriga financiranja: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je skrit — klikni Prikaži IBAN za prikaz',
        'show' => 'Prikaži IBAN',
        'hide' => 'Skrij IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Obvestilo o zasebnosti za osebni stik',
        'body' => '🔒 To je osebni stik. IBAN in osebni podatki so privzeto skriti in nikoli niso vključeni v izvoze.',
    ],

    'self_stub' => [
        'aria' => 'Ni prava nasprotna stranka',
        'heading' => 'To pravzaprav ni nasprotna stranka',

        'body_rest_html' => ' se tukaj pojavi, ker se v tvojih transakcijah pojavlja kot člen v verigi financiranja med računi. A to je <strong>tvoj lastni račun</strong>, ne nekdo, s katerim posluješ.',
        'body2' => 'Odpri pogled računa za stanje, izpiske in celotno zgodovino transakcij.',
        'open_cta' => 'Odpri pogled računa :name →',
        'hide_cta' => 'Skrij s tega seznama',
        'recent_legs' => 'Nedavni členi med računi',
    ],
];
