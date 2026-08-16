<?php

declare(strict_types=1);

return [
    'page_title' => 'Upload kontoudtog',
    'heading' => 'Upload kontoudtog',
    'migrate_prompt' => 'Skifter du fra en anden budgetapp?',
    'migrate_link' => 'Importér fra YNAB eller Actual',
    'subtitle' => 'Slip en eksport fra bank, kort eller PayPal ind, eller en fil med kvitteringsmails.',
    'mime_hint' => 'Filen ligner ikke en understøttet eksport af kontoudtog. Slip en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, et kortudtog i PDF, en e-mail (.eml) eller et postkassearkiv (.mbox) ind.',

    'source_label' => 'Kilde',

    'issuer_other_bank' => 'Anden bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-mailfil (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Fil',
    'submit' => 'Upload kontoudtog',

    'formats' => [
        'activity_download' => 'Aktivitetsdownload (CSV)',
        'email_message' => 'E-mail (.eml)',
        'mailbox_archive' => 'Postkassearkiv (.mbox)',
        'ing_nl' => 'ING Nederlandene (CSV)',
    ],

    'errors' => [
        'file_max' => 'Filen er for stor. Slip en eksport af kontoudtog ind, der er under størrelsesgrænsen for det valgte format.',
        'file_extensions' => 'Filen ligner ikke en understøttet eksport af kontoudtog. Slip en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, et kortudtog i PDF, en e-mail (.eml) eller et postkassearkiv (.mbox) ind.',
        'issuer_format' => 'Værdien :attribute er ikke gyldig for kilden :source.',
        'process_failed' => 'Filen kunne ikke behandles (:class). Hele fejlen findes i /dev/logs.',
    ],
];
