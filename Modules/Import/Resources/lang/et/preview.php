<?php

declare(strict_types=1);

return [
    'page_title' => 'Impordi eelvaade',
    'heading' => 'Impordi eelvaade',
    'discard' => 'Loobu impordist',
    'confirm' => 'Kinnita import',
    'subtitle' => 'Vaata töödeldud read üle. Enne kinnitamist ei salvestata pearaamatusse midagi.',

    'already_imported' => 'See fail on juba imporditud.',

    'already_imported_link' => 'Vaata impordi tulemust',


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
        'failed_detail' => 'üksikasjad on tööde logis',
        'open_horizon' => 'Ava Horizon',
        'failed_suffix' => 'et uuesti proovida või uurida.',
    ],

    'errors' => [
        'app_locked' => 'Importimiseks avage rakendus: krüpteerimisvõtmeid ei saa lukustatuna kasutada.',
        'file_unreadable' => 'Seda faili ei õnnestunud lugeda.',
        'iban_not_in_preview' => 'See IBAN ei kuulu praegusesse eelvaatesse.',
        'row_unreadable' => 'Seda rida ei õnnestunud lugeda.',
        'unknown_account' => 'See rida kuulub kontole, millele sa pole veel nime andnud.',
    ],

    'failed' => [
        'heading' => 'Seda faili ei õnnestunud lugeda',
        'no_rows' => 'Sellest failist ei leitud tehinguid, seega pole midagi importida.',
        'nothing_read' => 'Midagi selles failis ei õnnestunud tehinguna lugeda, seega pole midagi importida.',
        'every_row' => 'Ühtegi rida sellest failist ei õnnestunud lugeda, seega pole midagi importida. Iga rida on allpool koos põhjusega.',
        'likely_cause' => 'Tavaliselt ei sobi päiserida valitud allikaga. Kontrolli panka ja vormingut üleslaadimise ekraanil või laadi väljavõte pangast uuesti alla.',
        'truncated_heading' => 'Sellest failist õnnestus lugeda ainult osa',
        'truncated' => 'Lugemine peatus keset faili. Kõik pärast seda punkti jäi lugemata ja seda ei impordita.',
        'some_rows' => 'Mõningaid ridu ei õnnestunud lugeda. Need on allpool märgitud ja jäetakse vahele; kinnitamine impordib ülejäänu.',
        'detail_label' => 'Mida parser teatas:',
        'rows_read_label' => 'Loetud read',
        'rows_skipped_label' => 'Vahele jäetud read',
    ],
];
