<?php

declare(strict_types=1);

return [
    'page_title' => 'Ladda upp kontoutdrag',
    'heading' => 'Ladda upp kontoutdrag',
    'migrate_prompt' => 'Byter du från en annan budgetapp?',
    'migrate_link' => 'Importera från YNAB eller Actual',
    'subtitle' => 'Släpp in ett kontoutdrag som CSV, CAMT.053, MT940 eller PDF, eller en fil med kvitton från e-post.',
    'mime_hint' => 'Filer som stöds: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF med kortutdrag, e-postmeddelande (.eml) eller brevlådearkiv (.mbox).',

    'type_label' => 'Importtyp',

    'types' => [
        'csv' => 'CSV-fil',
        'camt053' => 'CAMT.053-kontoutdrag (XML)',
        'mt940' => 'MT940-kontoutdrag',
        'pdf' => 'Kortutdrag (PDF)',
        'email' => 'Kvittofil från e-post',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Formatet sattes till :format för att matcha filen du valde. Ändra det om det inte stämmer.',
    'file_label' => 'Fil',
    'submit' => 'Ladda upp kontoutdrag',

    'formats' => [
        'activity_download' => 'Aktivitetsnedladdning (CSV)',
        'email_message' => 'E-postmeddelande (.eml)',
        'mailbox_archive' => 'Brevlådearkiv (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Filen är för stor. Släpp in en export av kontoutdrag som ligger under storleksgränsen för det valda formatet.',
        'file_extensions' => 'Filen ser inte ut som en export av kontoutdrag som stöds. Släpp in en bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053-XML, ett kontoutdrag för kort i PDF, ett e-postmeddelande (.eml) eller ett brevlådearkiv (.mbox).',
        'type_format' => 'Värdet :attribute är inte giltigt för importtypen :type.',
        'process_failed' => 'Det gick inte att bearbeta filen (:class). Hela felet finns i /dev/logs.',
    ],
];
