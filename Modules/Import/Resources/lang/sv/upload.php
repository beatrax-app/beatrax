<?php

declare(strict_types=1);

return [
    'page_title' => 'Ladda upp kontoutdrag',
    'heading' => 'Ladda upp kontoutdrag',
    'migrate_prompt' => 'Byter du från en annan budgetapp?',
    'migrate_link' => 'Importera från YNAB eller Actual',
    'subtitle' => 'Släpp in en export från bank, kort eller PayPal, eller en fil med kvitton från e-post.',
    'mime_hint' => 'Filer som stöds: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF med kortutdrag, e-postmeddelande (.eml) eller brevlådearkiv (.mbox).',

    'source_label' => 'Källa',

    'issuer_other_bank' => 'Annan bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-postfil (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Fil',
    'submit' => 'Ladda upp kontoutdrag',

    'formats' => [
        'activity_download' => 'Aktivitetsnedladdning (CSV)',
        'email_message' => 'E-postmeddelande (.eml)',
        'mailbox_archive' => 'Brevlådearkiv (.mbox)',
        'ing_nl' => 'ING Nederländerna (CSV)',
    ],

    'errors' => [
        'file_max' => 'Filen är för stor. Släpp in en export av kontoutdrag som ligger under storleksgränsen för det valda formatet.',
        'file_extensions' => 'Filen ser inte ut som en export av kontoutdrag som stöds. Släpp in en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, ett kontoutdrag för kort i PDF, ett e-postmeddelande (.eml) eller ett brevlådearkiv (.mbox).',
        'issuer_format' => 'Värdet :attribute är inte giltigt för källan :source.',
        'process_failed' => 'Det gick inte att bearbeta filen (:class). Hela felet finns i /dev/logs.',
    ],
];
