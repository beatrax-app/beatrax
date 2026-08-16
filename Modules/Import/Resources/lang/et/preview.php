<?php

declare(strict_types=1);

return [
    'page_title' => 'Impordi eelvaade',
    'heading' => 'Impordi eelvaade',
    'discard' => 'Loobu impordist',
    'confirm' => 'Kinnita import',
    'subtitle' => 'Vaata töödeldud read üle. Enne kinnitamist ei salvestata pearaamatusse midagi.',

    'expired_html' => 'Eelvaade on aegunud. <a href="/imports/new" class="underline">Laadi fail uuesti üles</a> ja proovi uuesti.',

    'save_name' => 'Salvesta nimi',
    'account_name_label' => 'Konto nimi',
    'account_placeholder' => 'nt Peamine kogumiskonto',
    'rename_aria' => 'Nimeta see vastaspool ümber',

    'unknown_iban_prefix' => 'Leidsime tundmatu IBAN-i:',
    'unknown_iban_suffix' => 'Anna sellele kontole nimi.',

    'ics' => [
        'heading' => 'Anna oma ICS kaardikontole nimi.',
        'help' => 'See on esimene kord, kui impordid ICS andmeid. Anna sellele kaardile nimi, et see kuvatakse kogu rakenduses ühtmoodi.',
        'placeholder' => 'nt ICS kaart',
    ],

    'paypal' => [
        'heading' => 'Anna oma PayPali kontole nimi.',
        'help' => 'See on esimene kord, kui impordid PayPali andmeid. Anna sellele rahakotile nimi, et see kuvatakse kogu rakenduses ühtmoodi.',
        'placeholder' => 'nt PayPal',
    ],

    'col_date' => 'Kuupäev',
    'col_funding_source' => 'Rahastusallikas',
    'col_counterparty' => 'Vastaspool',
    'col_amount' => 'Summa',
    'col_status' => 'Olek',

    'status' => [
        'new' => 'Uus',
        'new_title' => 'Lisatakse sinu pearaamatusse.',
        'duplicate' => 'Duplikaat',
        'duplicate_title' => 'Juba imporditud — jäetakse vahele.',
        'enriched' => 'Täiendatud',
        'enriched_title' => 'Olemasolevat rida uuendatakse tugevama allikaviitega.',
        'error' => 'Viga',
    ],

    'chain' => [
        'heading' => 'Lahendan ahelaid…',
        'pending' => 'Järjekorras. Ahelate lahendaja alustab peagi.',
        'running' => 'Seon rahastusahelaid ja lahutan väljavõtte arveldusi.',
        'failed_prefix' => 'Ahelate lahendamine ebaõnnestus:',
        'unknown_error' => 'tekkis tundmatu viga',
        'open_horizon' => 'Ava Horizon',
        'failed_suffix' => 'et uuesti proovida või uurida.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'See IBAN ei kuulu praegusesse eelvaatesse.',
    ],
];
