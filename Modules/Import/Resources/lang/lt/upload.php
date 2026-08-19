<?php

declare(strict_types=1);

return [
    'page_title' => 'Įkelti išrašą',
    'heading' => 'Įkelti išrašą',
    'migrate_prompt' => 'Pereini iš kitos biudžeto programėlės?',
    'migrate_link' => 'Importuoti iš YNAB arba Actual',
    'subtitle' => 'Įkelk banko, kortelės ar PayPal eksportą arba el. pašto kvito failą.',
    'mime_hint' => 'Palaikomi failai: banko CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kortelės išrašo PDF, el. laiškas (.eml) arba pašto dėžutės archyvas (.mbox).',

    'source_label' => 'Šaltinis',

    'issuer_other_bank' => 'Kitas bankas (N26, Revolut, ING…)',
    'issuer_email_file' => 'El. pašto failas (.eml, .mbox)',

    'format_label' => 'Formatas',
    'file_label' => 'Failas',
    'submit' => 'Įkelti išrašą',

    'formats' => [
        'activity_download' => 'Veiklos ataskaita (CSV)',
        'email_message' => 'El. laiškas (.eml)',
        'mailbox_archive' => 'Pašto dėžutės archyvas (.mbox)',
        'ing_nl' => 'ING Nyderlandai (CSV)',
    ],

    'errors' => [
        'file_max' => 'Šis failas per didelis. Įkelk išrašo eksportą, neviršijantį pasirinkto formato dydžio ribos.',
        'file_extensions' => 'Šis failas nepanašus į palaikomą išrašo eksportą. Įkelk banko CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kortelės išrašo PDF, el. laišką (.eml) arba pašto dėžutės archyvą (.mbox).',
        'issuer_format' => 'Lauko :attribute reikšmė netinka šaltiniui :source.',
        'process_failed' => 'Šio failo apdoroti nepavyko (:class). Visą klaidą rasi /dev/logs.',
    ],
];
