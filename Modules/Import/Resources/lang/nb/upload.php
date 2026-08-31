<?php

declare(strict_types=1);

return [
    'page_title' => 'Last opp kontoutskrift',
    'heading' => 'Last opp kontoutskrift',
    'migrate_prompt' => 'Bytter du fra en annen budsjettapp?',
    'migrate_link' => 'Importer fra YNAB eller Actual',
    'subtitle' => 'Slipp inn en kontoutskrift som CSV, CAMT.053, MT940 eller PDF, eller en fil med kvitteringer fra e-post.',
    'mime_hint' => 'Støttede filer: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF med kortutskrift, e-postmelding (.eml) eller postkassearkiv (.mbox).',

    'type_label' => 'Importtype',

    'types' => [
        'csv' => 'CSV-fil',
        'camt053' => 'CAMT.053-kontoutskrift (XML)',
        'mt940' => 'MT940-kontoutskrift',
        'pdf' => 'Kortutskrift (PDF)',
        'email' => 'Kvitteringsfil fra e-post',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Formatet ble satt til :format for å passe filen du valgte. Endre det hvis det ikke stemmer.',
    'file_label' => 'Fil',
    'submit' => 'Last opp kontoutskrift',

    'formats' => [
        'activity_download' => 'Aktivitetsnedlasting (CSV)',
        'email_message' => 'E-postmelding (.eml)',
        'mailbox_archive' => 'Postkassearkiv (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Filen er for stor. Slipp inn en eksport av kontoutskrift som er under størrelsesgrensen for det valgte formatet.',
        'file_extensions' => 'Filen ser ikke ut som en støttet eksport av kontoutskrift. Slipp inn en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, en kortutskrift i PDF, en e-postmelding (.eml) eller et postkassearkiv (.mbox).',
        'type_format' => 'Verdien :attribute er ikke gyldig for importtypen :type.',
        'process_failed' => 'Filen kunne ikke behandles (:class). Hele feilen ligger i /dev/logs.',
    ],
];
