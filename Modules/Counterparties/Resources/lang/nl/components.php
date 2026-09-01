<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Type tegenpartij: :type',
        'merchant' => 'Winkelier',
        'personal' => 'Persoonlijk',
        'bank' => 'Bank',
        'government' => 'Overheid',
        'self' => 'Zelf',
        'unknown' => 'Onbekend',
    ],

    'filter_chips' => [
        'aria' => 'Filteren op type',
        'all' => 'Alle',
        'merchant' => 'Winkeliers',
        'personal' => 'Persoonlijk',
        'bank' => 'Banken',
        'government' => 'Overheid',
        'self' => 'Zelf',
        'unknown' => 'Onbekend',
    ],

    'default_name' => [
        'bank_fee' => 'Bankkosten',
    ],

    'cp_card' => [
        'aria' => 'Tegenpartij: :name',
        'recent_aria' => 'Recente activiteit',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Financieringsketen: ',
        'join' => ' naar ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN verborgen — klik op IBAN tonen om te onthullen',
        'hidden_aria_touch' => 'IBAN verborgen — tik op IBAN tonen om te onthullen',
        'show' => 'IBAN tonen',
        'hide' => 'IBAN verbergen',
    ],

    'privacy_banner' => [
        'aria' => 'Privacymelding voor persoonlijk contact',
        'body' => '🔒 Dit is een persoonlijk contact. IBAN en persoonlijke gegevens zijn standaard verborgen en worden nooit gedeeld in exports.',
    ],

    'self_stub' => [
        'aria' => 'Geen echte tegenpartij',
        'heading' => 'Dit is niet echt een tegenpartij',
        'body_rest_html' => ' verschijnt hier omdat het in je transacties opduikt als de financieringsschakel tussen rekeningen. Maar het is <strong>je eigen rekening</strong>, niet iemand met wie je transacties doet.',
        'body2' => 'Open de rekeningweergave voor saldo, afschriften en de volledige transactiegeschiedenis.',
        'open_cta' => ':name rekeningweergave openen →',
        'hide_cta' => 'Verbergen uit deze lijst',
        'recent_legs' => 'Recente schakels tussen rekeningen',
    ],
];
