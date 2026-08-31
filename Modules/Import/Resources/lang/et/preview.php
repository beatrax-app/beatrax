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

    'unknown_account_prefix' => 'Leidsime tundmatu konto:',
    'unknown_iban_suffix' => 'Anna sellele kontole nimi.',

    'ics' => [
        'name' => 'ICS kaart',
        'heading' => 'Anna oma ICS kaardikontole nimi.',
        'help' => 'See on esimene kord, kui impordid ICS andmeid. Anna sellele kaardile nimi, et see kuvatakse kogu rakenduses ühtmoodi.',
        'placeholder' => 'nt ICS kaart',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Anna oma PayPali kontole nimi.',
        'help' => 'See on esimene kord, kui impordid PayPali andmeid. Anna sellele rahakotile nimi, et see kuvatakse kogu rakenduses ühtmoodi.',
        'placeholder' => 'nt PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Anna oma Google Play kontole nimi.',
        'help' => 'See on esimene kord, kui impordid Google Play kviitungi. Anna sellele kontole nimi, et see kuvatakse kogu rakenduses ühtmoodi.',
        'placeholder' => 'nt Google Play',
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

    'rows_shown' => 'Kuvatud read: :shown / :total',

    'show_more' => 'Näita rohkem ridu',

    'errors' => [
        'app_locked' => 'Importimiseks avage rakendus: krüpteerimisvõtmeid ei saa lukustatuna kasutada.',
        'archive_holds_one_message' => 'See fail on üksainus e-kiri, mitte postkasti arhiiv, nii et arhiivina loetuna pole selles midagi. Laadi see uuesti üles vorminguga E-kiri.',
        'email_file_is_an_archive' => 'See fail on postkasti arhiiv: selles on rohkem kui üks kiri, ja ühe kirjana loetuna võetaks sealt ainult esimene. Laadi see uuesti üles vorminguga Postkasti arhiiv.',
        'file_stopped_short' => 'Päiserida klappis, seega on vorming õige. Lugemine peatus enne faili lõppu. Selle põhjustab üks loetamatu rida, samuti selle seadme jaoks liiga suur fail. Proovi lühemat ajavahemikku.',
        'file_unreadable' => 'Seda faili ei õnnestunud lugeda.',
        'file_unreadable_detail' => 'Rakendus ei suutnud seda faili lugeda (:code). Täielikud üksikasjad on rakenduse logis; probleemist teatades viita sellele koodile.',
        'iban_not_in_preview' => 'See IBAN ei kuulu praegusesse eelvaatesse.',
        'not_an_email_file' => 'See fail pole ei e-kiri ega postkasti arhiiv, nii et sealt pole midagi kviitungina lugeda. Vali impordi tüüp ja vorming, mis sinu failile vastavad.',
        'pdf_has_no_text_layer' => 'See PDF ei sisalda teksti — see on väljavõtte skann või foto, nii et sealt pole midagi lugeda. Laadi pangast alla väljavõte ise või kasuta CSV-eksporti.',
        'pdf_password_protected' => 'See PDF on parooliga kaitstud, nii et ükski lugeja ei ava seda. Salvesta oma PDF-vaaturist kaitseta koopia ja impordi see.',
        'pdf_reader_unavailable' => 'Sellel rakenduse versioonil pole üldse PDF-lugejat, nii et PDF-väljavõtet ei saa siin avada. Impordi see fail teises seadmes või kasuta hoopis panga CSV-eksporti.',
        'row_belongs_to_another_statement' => 'See rida kuulub tehingu juurde, mis on teises väljavõttefailis. Impordi ka see väljavõte — need kaks loetakse koos.',
        'row_unreadable' => 'Seda rida ei õnnestunud lugeda.',
        'row_unreadable_detail' => 'Rakendus ei suutnud seda rida lugeda (:code). Täielikud üksikasjad on rakenduse logis; probleemist teatades viita sellele koodile.',
        'unknown_account' => 'See rida kuulub kontole, millele sa pole veel nime andnud.',
    ],

    'receipts' => [
        'heading' => 'See fail loeti e-kirjana',
        'saved' => 'Mida see sisaldas, on all, ja iga sõnum on salvestatud.',
        'none_imported' => 'Ükski neist ei saanud tehinguks, seega pearaamatusse ei lisatud midagi.',
        'shown' => 'Kuvatud sõnumid: :shown / :total',
        'no_subject' => 'Teemata',

        'state' => [
            'read' => 'Loetud maksena — kinnita see import, et see pearaamatusse jõuaks.',
            'not_a_payment' => 'Pole makse. See sõnum teatab millestki, mitte ei kinnita makset.',
            'unreadable' => 'Salvestatud. Rakendus loeb selle saatja kviitungeid, kuid ei leidnud sellest sõnumist summat, kaupmeest ega viidet.',
            'unknown_sender' => 'Salvestatud. Rakendus ei loe selle saatja kviitungeid, seega ei võtnud sõnumist midagi.',
        ],
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
