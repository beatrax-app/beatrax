<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Typ av motpart: :type',
        'merchant' => 'Handlare',
        'personal' => 'Privat',
        'bank' => 'Bank',
        'government' => 'Myndighet',
        'self' => 'Egen',
        'unknown' => 'Okänd',
    ],

    'filter_chips' => [
        'aria' => 'Filtrera efter typ',
        'all' => 'Alla',
        'merchant' => 'Handlare',
        'personal' => 'Privat',
        'bank' => 'Banker',
        'government' => 'Myndigheter',
        'self' => 'Egna',
        'unknown' => 'Okända',
    ],

    'default_name' => [
        'bank_fee' => 'Bankavgift',
    ],

    'cp_card' => [
        'aria' => 'Motpart: :name',
        'recent_aria' => 'Senaste aktivitet',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansieringskedja: ',
        'join' => ' till ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN dolt — klicka på Visa IBAN för att visa det',
        'show' => 'Visa IBAN',
        'hide' => 'Dölj IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Integritetsmeddelande för privat kontakt',
        'body' => '🔒 Det här är en privat kontakt. IBAN och personuppgifter är dolda som standard och delas aldrig i exporter.',
    ],

    'self_stub' => [
        'aria' => 'Ingen verklig motpart',
        'heading' => 'Det här är egentligen inte en motpart',

        'body_rest_html' => ' visas här eftersom det dyker upp i dina transaktioner som finansieringsledet mellan konton. Men det är <strong>ditt eget konto</strong>, inte någon du gör affärer med.',
        'body2' => 'Öppna kontovyn för saldo, kontoutdrag och fullständig transaktionshistorik.',
        'open_cta' => 'Öppna kontovyn för :name →',
        'hide_cta' => 'Dölj från den här listan',
        'recent_legs' => 'Senaste leden mellan konton',
    ],
];
