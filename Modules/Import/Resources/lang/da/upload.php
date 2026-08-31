<?php

declare(strict_types=1);

return [
    'page_title' => 'Upload kontoudtog',
    'heading' => 'Upload kontoudtog',
    'migrate_prompt' => 'Skifter du fra en anden budgetapp?',
    'migrate_link' => 'Importér fra YNAB eller Actual',
    'subtitle' => 'Slip et kontoudtog i CSV, CAMT.053, MT940 eller PDF ind, eller en fil med kvitteringsmails.',
    'mime_hint' => 'Understøttede filer: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF med kortudtog, e-mailbesked (.eml) eller postkassearkiv (.mbox).',

    'type_label' => 'Importtype',

    'types' => [
        'csv' => 'CSV-fil',
        'camt053' => 'CAMT.053-kontoudtog (XML)',
        'mt940' => 'MT940-kontoudtog',
        'pdf' => 'Kortudtog (PDF)',
        'email' => 'Fil med kvitteringsmail',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Formatet blev sat til :format, så det passer til filen du valgte. Ret det, hvis det er forkert.',
    'file_label' => 'Fil',
    'submit' => 'Upload kontoudtog',

    'formats' => [
        'activity_download' => 'Aktivitetsdownload (CSV)',
        'email_message' => 'E-mail (.eml)',
        'mailbox_archive' => 'Postkassearkiv (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Filen er for stor. Slip en eksport af kontoudtog ind, der er under størrelsesgrænsen for det valgte format.',
        'file_extensions' => 'Filen ligner ikke en understøttet eksport af kontoudtog. Slip en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, et kortudtog i PDF, en e-mail (.eml) eller et postkassearkiv (.mbox) ind.',
        'type_format' => 'Værdien :attribute er ikke gyldig for importtypen :type.',
        'process_failed' => 'Filen kunne ikke behandles (:class). Hele fejlen findes i /dev/logs.',
    ],
];
