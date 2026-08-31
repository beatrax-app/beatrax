<?php

declare(strict_types=1);

return [
    'page_title' => 'Įkelti išrašą',
    'heading' => 'Įkelti išrašą',
    'migrate_prompt' => 'Pereini iš kitos biudžeto programėlės?',
    'migrate_link' => 'Importuoti iš YNAB arba Actual',
    'subtitle' => 'Įkelk išrašą CSV, CAMT.053, MT940 ar PDF formatu arba el. pašto kvito failą.',
    'mime_hint' => 'Palaikomi failai: banko CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kortelės išrašo PDF, el. laiškas (.eml) arba pašto dėžutės archyvas (.mbox).',

    'type_label' => 'Importo tipas',

    'types' => [
        'csv' => 'CSV failas',
        'camt053' => 'CAMT.053 išrašas (XML)',
        'mt940' => 'MT940 išrašas',
        'pdf' => 'Kortelės išrašas (PDF)',
        'email' => 'El. pašto kvito failas',
    ],

    'format_label' => 'Formatas',

    'format_from_file' => 'Formatas nustatytas į :format, kad atitiktų pasirinktą failą. Pakeisk jį, jei tai neteisinga.',
    'file_label' => 'Failas',
    'submit' => 'Įkelti išrašą',

    'formats' => [
        'activity_download' => 'Veiklos ataskaita (CSV)',
        'email_message' => 'El. laiškas (.eml)',
        'mailbox_archive' => 'Pašto dėžutės archyvas (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Šis failas per didelis. Įkelk išrašo eksportą, neviršijantį pasirinkto formato dydžio ribos.',
        'file_extensions' => 'Šis failas nepanašus į palaikomą išrašo eksportą. Įkelk banko CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kortelės išrašo PDF, el. laišką (.eml) arba pašto dėžutės archyvą (.mbox).',
        'type_format' => 'Lauko :attribute reikšmė netinka importo tipui :type.',
        'process_failed' => 'Šio failo apdoroti nepavyko (:class). Visą klaidą rasi /dev/logs.',
    ],
];
