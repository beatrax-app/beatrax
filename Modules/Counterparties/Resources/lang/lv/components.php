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

    'default_name' => [
        'bank_fee' => 'Bankas komisija',
        'account_maintenance' => 'Konta apkalpošanas maksa',
        'monthly_fee' => 'Mēneša maksa',
        'quarterly_fee' => 'Ceturkšņa maksa',
        'annual_fee' => 'Gada maksa',
        'card_fee' => 'Kartes maksa',
        'transaction_fee' => 'Darījuma maksa',
        'transfer_fee' => 'Pārskaitījuma maksa',
        'withdrawal_fee' => 'Izņemšanas maksa',
        'transaction_levy' => 'Darījumu nodoklis',
        'foreign_transaction_fee' => 'Valūtas maiņas maksa',
        'commission' => 'Komisijas maksa',
        'debit_interest' => 'Debeta procenti',
        'overdraft' => 'Overdrafta maksa',
        'overdraft_interest' => 'Overdrafta procenti',
        'insufficient_funds' => 'Maksa par nepietiekamiem līdzekļiem',
        'penalty_fee' => 'Soda maksa',
        'loan_arrangement_fee' => 'Kredīta noformēšanas maksa',
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
        // i18n-review: lv · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN ir paslēpts — pieskarieties Rādīt IBAN, lai to atklātu',
        'show' => 'Rādīt IBAN',
        'hide' => 'Slēpt IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privātuma paziņojums par privātu kontaktu',
        'body' => '🔒 Šis ir privāts kontakts. IBAN paliek paslēpts, līdz to parādāt, un eksportos tas nenonāk. Vārds joprojām redzams visur, kur redzami šī kontakta darījumi.',
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
