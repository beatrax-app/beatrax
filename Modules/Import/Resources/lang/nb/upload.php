<?php

declare(strict_types=1);

return [
    'page_title' => 'Last opp kontoutskrift',
    'heading' => 'Last opp kontoutskrift',
    'migrate_prompt' => 'Bytter du fra en annen budsjettapp?',
    'migrate_link' => 'Importer fra YNAB eller Actual',
    'subtitle' => 'Slipp inn en eksport fra bank, kort eller PayPal, eller en fil med kvitteringer fra e-post.',
    'mime_hint' => 'Støttede filer: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF med kortutskrift, e-postmelding (.eml) eller postkassearkiv (.mbox).',

    'source_label' => 'Kilde',

    'issuer_other_bank' => 'Annen bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-postfil (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Fil',
    'submit' => 'Last opp kontoutskrift',

    'formats' => [
        'activity_download' => 'Aktivitetsnedlasting (CSV)',
        'email_message' => 'E-postmelding (.eml)',
        'mailbox_archive' => 'Postkassearkiv (.mbox)',
        'ing_nl' => 'ING Nederland (CSV)',
    ],

    'errors' => [
        'file_max' => 'Filen er for stor. Slipp inn en eksport av kontoutskrift som er under størrelsesgrensen for det valgte formatet.',
        'file_extensions' => 'Filen ser ikke ut som en støttet eksport av kontoutskrift. Slipp inn en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, en kortutskrift i PDF, en e-postmelding (.eml) eller et postkassearkiv (.mbox).',
        'issuer_format' => 'Verdien :attribute er ikke gyldig for kilden :source.',
        'process_failed' => 'Filen kunne ikke behandles (:class). Hele feilen ligger i /dev/logs.',
    ],
];
