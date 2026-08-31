<?php

declare(strict_types=1);

return [
    'page_title' => 'Laadi väljavõte üles',
    'heading' => 'Laadi väljavõte üles',
    'migrate_prompt' => 'Kolid teisest eelarverakendusest?',
    'migrate_link' => 'Impordi YNAB-ist või Actualist',
    'subtitle' => 'Lohista siia väljavõte CSV-, CAMT.053-, MT940- või PDF-vormingus või e-posti kviitungi fail.',
    'mime_hint' => 'Toetatud failid: panga CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kaardiväljavõtte PDF, e-kirja sõnum (.eml) või postkastiarhiiv (.mbox).',

    'type_label' => 'Impordi tüüp',

    'types' => [
        'csv' => 'CSV-fail',
        'camt053' => 'CAMT.053 väljavõte (XML)',
        'mt940' => 'MT940 väljavõte',
        'pdf' => 'Kaardiväljavõte (PDF)',
        'email' => 'E-posti kviitungi fail',
    ],

    'format_label' => 'Vorming',

    'format_from_file' => 'Vorming seati väärtusele :format, et see vastaks valitud failile. Muuda seda, kui see pole õige.',
    'file_label' => 'Fail',
    'submit' => 'Laadi väljavõte üles',

    'formats' => [
        'activity_download' => 'Tegevuste allalaadimine (CSV)',
        'email_message' => 'E-kiri (.eml)',
        'mailbox_archive' => 'Postkasti arhiiv (.mbox)',
    ],

    'errors' => [
        'file_max' => 'See fail on liiga suur. Lohista siia väljavõtte eksport, mis jääb valitud vormingu suurusepiirist väiksemaks.',
        'file_extensions' => 'See fail ei tundu olevat toetatud väljavõtte eksport. Lohista siia panga CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kaardiväljavõtte PDF, e-kiri (.eml) või postkasti arhiiv (.mbox).',
        'type_format' => 'Väärtus :attribute ei sobi impordi tüübile :type.',
        'process_failed' => 'Seda faili ei õnnestunud töödelda (:class). Täielik viga on kaustas /dev/logs.',
    ],
];
