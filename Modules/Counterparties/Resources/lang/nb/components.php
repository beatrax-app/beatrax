<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Motpartstype: :type',
        'merchant' => 'Forhandler',
        'personal' => 'Privat',
        'bank' => 'Bank',
        'government' => 'Offentlig etat',
        'self' => 'Egen',
        'unknown' => 'Ukjent',
    ],

    'filter_chips' => [
        'aria' => 'Filtrer etter type',
        'all' => 'Alle',
        'merchant' => 'Forhandlere',
        'personal' => 'Privat',
        'bank' => 'Banker',
        'government' => 'Offentlige etater',
        'self' => 'Egne',
        'unknown' => 'Ukjente',
    ],

    'cp_card' => [
        'aria' => 'Motpart: :name',
        'recent_aria' => 'Siste aktivitet',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansieringskjede: ',
        'join' => ' til ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN skjult — klikk på Vis IBAN for å vise det',
        'show' => 'Vis IBAN',
        'hide' => 'Skjul IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Personvernmelding for privat kontakt',
        'body' => '🔒 Dette er en privat kontakt. IBAN og personopplysninger er skjult som standard og deles aldri i eksporter.',
    ],

    'self_stub' => [
        'aria' => 'Ikke en ekte motpart',
        'heading' => 'Dette er egentlig ikke en motpart',

        'body_rest_html' => ' vises her fordi det dukker opp i transaksjonene dine som finansieringsleddet mellom kontoer. Men det er <strong>din egen konto</strong>, ikke noen du handler med.',
        'body2' => 'Åpne kontovisningen for saldo, kontoutskrifter og full transaksjonshistorikk.',
        'open_cta' => 'Åpne kontovisningen for :name →',
        'hide_cta' => 'Skjul fra denne listen',
        'recent_legs' => 'Siste ledd mellom kontoer',
    ],
];
