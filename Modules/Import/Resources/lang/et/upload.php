<?php

declare(strict_types=1);

return [
    'page_title' => 'Laadi väljavõte üles',
    'heading' => 'Laadi väljavõte üles',
    'migrate_prompt' => 'Kolid teisest eelarverakendusest?',
    'migrate_link' => 'Impordi YNAB-ist või Actualist',
    'subtitle' => 'Lohista siia panga, kaardi või PayPali eksport või e-posti kviitungi fail.',
    'mime_hint' => 'See fail ei tundu olevat toetatud väljavõtte eksport. Lohista siia panga CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kaardiväljavõtte PDF, e-kiri (.eml) või postkasti arhiiv (.mbox).',

    'source_label' => 'Allikas',

    'issuer_other_bank' => 'Muu pank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-posti fail (.eml, .mbox)',

    'format_label' => 'Vorming',
    'file_label' => 'Fail',
    'submit' => 'Laadi väljavõte üles',

    'formats' => [
        'activity_download' => 'Tegevuste allalaadimine (CSV)',
        'email_message' => 'E-kiri (.eml)',
        'mailbox_archive' => 'Postkasti arhiiv (.mbox)',
        'ing_nl' => 'ING Holland (CSV)',
    ],

    'errors' => [
        'file_max' => 'See fail on liiga suur. Lohista siia väljavõtte eksport, mis jääb valitud vormingu suurusepiirist väiksemaks.',
        'file_extensions' => 'See fail ei tundu olevat toetatud väljavõtte eksport. Lohista siia panga CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kaardiväljavõtte PDF, e-kiri (.eml) või postkasti arhiiv (.mbox).',
        'issuer_format' => 'Väärtus :attribute ei sobi allikale :source.',
        'process_failed' => 'Seda faili ei õnnestunud töödelda (:class). Täielik viga on kaustas /dev/logs.',
    ],
];
