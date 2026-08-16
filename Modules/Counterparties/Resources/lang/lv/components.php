<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Darījuma partnera veids: :type',
        'merchant' => 'Tirgotājs',
        'personal' => 'Privātpersona',
        'bank' => 'Banka',
        'government' => 'Valsts iestāde',
        'self' => 'Savs konts',
        'unknown' => 'Nezināms',
    ],

    'filter_chips' => [
        'aria' => 'Filtrēt pēc veida',
        'all' => 'Visi',
        'merchant' => 'Tirgotāji',
        'personal' => 'Privātpersonas',
        'bank' => 'Bankas',
        'government' => 'Valsts iestādes',
        'self' => 'Savi konti',
        'unknown' => 'Nezināmi',
    ],

    'cp_card' => [
        'aria' => 'Darījuma partneris: :name',
        'recent_aria' => 'Nesenā aktivitāte',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansējuma ķēde: ',
        'join' => ' uz ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN ir paslēpts — noklikšķiniet Rādīt IBAN, lai to atklātu',
        'show' => 'Rādīt IBAN',
        'hide' => 'Slēpt IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privātuma paziņojums par privātu kontaktu',
        'body' => '🔒 Šis ir privāts kontakts. IBAN un personas dati pēc noklusējuma ir paslēpti un nekad netiek iekļauti eksportos.',
    ],

    'self_stub' => [
        'aria' => 'Nav īsts darījuma partneris',
        'heading' => 'Šis īsti nav darījuma partneris',

        'body_rest_html' => ' parādās šeit tāpēc, ka jūsu darījumos tas ir finansējuma posms starp kontiem. Taču tas ir <strong>jūsu paša konts</strong>, nevis kāds, ar ko veic darījumus.',
        'body2' => 'Atveriet konta skatu, lai redzētu atlikumu, konta izrakstus un pilnu darījumu vēsturi.',
        'open_cta' => 'Atvērt konta :name skatu →',
        'hide_cta' => 'Paslēpt no šī saraksta',
        'recent_legs' => 'Nesenie starpkontu posmi',
    ],
];
