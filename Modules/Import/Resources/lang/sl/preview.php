<?php

declare(strict_types=1);

return [
    'page_title' => 'Predogled uvoza',
    'heading' => 'Predogled uvoza',
    'discard' => 'Zavrzi uvoz',
    'confirm' => 'Potrdi uvoz',
    'subtitle' => 'Preglej razčlenjene vrstice. Nič se ne shrani v tvojo glavno knjigo, dokler ne potrdiš.',

    'expired_html' => 'Predogled je potekel. <a href="/imports/new" class="underline">Znova naloži datoteko</a> in poskusi še enkrat.',

    'save_name' => 'Shrani ime',
    'account_name_label' => 'Ime računa',
    'account_placeholder' => 'npr. Glavni varčevalni račun',
    'rename_aria' => 'Preimenuj to nasprotno stranko',

    'unknown_iban_prefix' => 'Našli smo neznan IBAN:',
    'unknown_iban_suffix' => 'Poimenuj ta račun.',

    'ics' => [
        'heading' => 'Poimenuj svoj kartični račun ICS.',
        'help' => 'To je prvič, da uvažaš podatke ICS. Poimenuj to kartico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. kartica ICS',
    ],

    'paypal' => [
        'heading' => 'Poimenuj svoj račun PayPal.',
        'help' => 'To je prvič, da uvažaš podatke PayPal. Poimenuj to denarnico, da se bo dosledno prikazovala po vsej aplikaciji.',
        'placeholder' => 'npr. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Vir sredstev',
    'col_counterparty' => 'Nasprotna stranka',
    'col_amount' => 'Znesek',
    'col_status' => 'Stanje',

    'status' => [
        'new' => 'Novo',
        'new_title' => 'Dodano bo v tvojo glavno knjigo.',
        'duplicate' => 'Podvojeno',
        'duplicate_title' => 'Že uvoženo — preskočeno bo.',
        'enriched' => 'Obogateno',
        'enriched_title' => 'Obstoječa vrstica bo posodobljena z močnejšim sklicem na vir.',
        'error' => 'Napaka',
    ],

    'chain' => [
        'heading' => 'Razreševanje verig…',
        'pending' => 'V čakalni vrsti. Razreševanje verig se bo kmalu začelo.',
        'running' => 'Povezovanje verig financiranja in razstavljanje poravnav z izpiska.',
        'failed_prefix' => 'Razreševanje verig ni uspelo:',
        'unknown_error' => 'prišlo je do neznane napake',
        'open_horizon' => 'Odpri Horizon',
        'failed_suffix' => 'za ponovni poskus ali pregled.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Ta IBAN ni del trenutnega predogleda.',
    ],
];
