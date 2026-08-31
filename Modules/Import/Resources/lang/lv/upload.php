<?php

declare(strict_types=1);

return [
    'page_title' => 'Augšupielādēt konta izrakstu',
    'heading' => 'Augšupielādēt konta izrakstu',
    'migrate_prompt' => 'Pārejiet no citas budžeta lietotnes?',
    'migrate_link' => 'Importēt no YNAB vai Actual',
    'subtitle' => 'Ievelciet konta izrakstu CSV, CAMT.053, MT940 vai PDF formātā vai e-pasta čeka failu.',
    'mime_hint' => 'Atbalstītie faili: bankas CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kartes izraksta PDF, e-pasta ziņojums (.eml) vai pastkastes arhīvs (.mbox).',

    'type_label' => 'Importa veids',

    'types' => [
        'csv' => 'CSV fails',
        'camt053' => 'CAMT.053 izraksts (XML)',
        'mt940' => 'MT940 izraksts',
        'pdf' => 'Kartes izraksts (PDF)',
        'email' => 'E-pasta čeka fails',
    ],

    'format_label' => 'Formāts',

    'format_from_file' => 'Formāts iestatīts uz :format, lai atbilstu izvēlētajam failam. Nomaini to, ja tas nav pareizi.',
    'file_label' => 'Fails',
    'submit' => 'Augšupielādēt konta izrakstu',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'E-pasta ziņojums (.eml)',
        'mailbox_archive' => 'Pastkastes arhīvs (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Šis fails ir pārāk liels. Ievelciet konta izraksta eksportu, kas nepārsniedz izvēlētā formāta izmēra ierobežojumu.',
        'file_extensions' => 'Šis fails neizskatās pēc atbalstīta konta izraksta eksporta. Ievelciet bankas CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kartes izraksta PDF, e-pasta ziņojumu (.eml) vai pastkastes arhīvu (.mbox).',
        'type_format' => 'Vērtība :attribute nav derīga importa veidam :type.',
        'process_failed' => 'Neizdevās apstrādāt šo failu (:class). Pilns kļūdas apraksts ir /dev/logs.',
    ],
];
