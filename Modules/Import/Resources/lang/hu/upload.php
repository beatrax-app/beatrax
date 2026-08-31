<?php

declare(strict_types=1);

return [
    'page_title' => 'Számlakivonat feltöltése',
    'heading' => 'Számlakivonat feltöltése',
    'migrate_prompt' => 'Másik költségvetési alkalmazásról váltasz?',
    'migrate_link' => 'Import YNAB-ból vagy Actualból',
    'subtitle' => 'Húzz ide egy CSV-, CAMT.053-, MT940- vagy PDF-kivonatot, vagy egy e-mailes bizonylatfájlt.',
    'mime_hint' => 'Támogatott fájlok: banki CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kártyakivonat PDF, e-mail üzenet (.eml) vagy postafiók-archívum (.mbox).',

    'type_label' => 'Import típusa',

    'types' => [
        'csv' => 'CSV-fájl',
        'camt053' => 'CAMT.053 kivonat (XML)',
        'mt940' => 'MT940 kivonat',
        'pdf' => 'Kártyakivonat (PDF)',
        'email' => 'E-mailes bizonylatfájl',
    ],

    'format_label' => 'Formátum',

    'format_from_file' => 'A formátum :format lett, hogy illeszkedjen a kiválasztott fájlhoz. Módosítsd, ha ez nem stimmel.',
    'file_label' => 'Fájl',
    'submit' => 'Számlakivonat feltöltése',

    'formats' => [
        'activity_download' => 'Tevékenységletöltés (CSV)',
        'email_message' => 'E-mail-üzenet (.eml)',
        'mailbox_archive' => 'Postafiók-archívum (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ez a fájl túl nagy. Húzz ide olyan kivonatexportot, amely a választott formátum méretkorlátja alatt marad.',
        'file_extensions' => 'Ez a fájl nem tűnik támogatott kivonatexportnak. Húzz ide egy banki CSV-t, MT940-et (.sta / .mt940 / .txt), CAMT.053 XML-t, kártyakivonatot PDF-ben, e-mail-üzenetet (.eml) vagy postafiók-archívumot (.mbox).',
        'type_format' => 'A(z) :attribute értéke nem érvényes a(z) :type importtípushoz.',
        'process_failed' => 'Ezt a fájlt nem sikerült feldolgozni (:class). A teljes hiba a /dev/logs alatt található.',
    ],
];
