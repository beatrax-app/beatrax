<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Vastaspoole tüüp: :type',
        'merchant' => 'Kaupmees',
        'personal' => 'Eraisik',
        'bank' => 'Pank',
        'government' => 'Riik',
        'self' => 'Enda oma',
        'unknown' => 'Tundmatu',
    ],

    'filter_chips' => [
        'aria' => 'Filtreeri tüübi järgi',
        'all' => 'Kõik',
        'merchant' => 'Kaupmehed',
        'personal' => 'Eraisikud',
        'bank' => 'Pangad',
        'government' => 'Riik',
        'self' => 'Enda oma',
        'unknown' => 'Tundmatud',
    ],

    'default_name' => [
        'bank_fee' => 'Pangatasu',
    ],

    'cp_card' => [
        'aria' => 'Vastaspool: :name',
        'recent_aria' => 'Hiljutine tegevus',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Rahastusahel: ',
        'join' => ' kuni ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN on peidetud — selle nägemiseks klõpsa „Näita IBAN-i“',
        // i18n-review: et · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN on peidetud — selle nägemiseks puuduta „Näita IBAN-i“',
        'show' => 'Näita IBAN-i',
        'hide' => 'Peida IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privaatsusteade eraisikust kontakti kohta',
        'body' => '🔒 See on eraisikust kontakt. IBAN ja isikuandmed on vaikimisi peidetud ning neid ei jagata kunagi eksportides.',
    ],

    'self_stub' => [
        'aria' => 'Pole päris vastaspool',
        'heading' => 'See ei ole tegelikult vastaspool',

        'body_rest_html' => ' ilmub siia seetõttu, et see esineb sinu tehingutes kontodevahelise rahastuslülina. Kuid see on <strong>sinu enda konto</strong>, mitte keegi, kellega tehinguid teed.',
        'body2' => 'Ava konto vaade, et näha jääki, väljavõtteid ja kogu tehingute ajalugu.',
        'open_cta' => 'Ava konto :name vaade →',
        'hide_cta' => 'Peida sellest loendist',
        'recent_legs' => 'Hiljutised kontodevahelised lülid',
    ],
];
