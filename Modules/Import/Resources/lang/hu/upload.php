<?php

declare(strict_types=1);

return [
    'page_title' => 'Számlakivonat feltöltése',
    'heading' => 'Számlakivonat feltöltése',
    'migrate_prompt' => 'Másik költségvetési alkalmazásról váltasz?',
    'migrate_link' => 'Import YNAB-ból vagy Actualból',
    'subtitle' => 'Húzz ide egy banki, kártya- vagy PayPal-exportot, vagy egy e-mailes bizonylatfájlt.',
    'mime_hint' => 'Támogatott fájlok: banki CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kártyakivonat PDF, e-mail üzenet (.eml) vagy postafiók-archívum (.mbox).',

    'source_label' => 'Forrás',

    'issuer_other_bank' => 'Másik bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-mail-fájl (.eml, .mbox)',

    'format_label' => 'Formátum',
    'file_label' => 'Fájl',
    'submit' => 'Számlakivonat feltöltése',

    'formats' => [
        'activity_download' => 'Tevékenységletöltés (CSV)',
        'email_message' => 'E-mail-üzenet (.eml)',
        'mailbox_archive' => 'Postafiók-archívum (.mbox)',
        'ing_nl' => 'ING Hollandia (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ez a fájl túl nagy. Húzz ide olyan kivonatexportot, amely a választott formátum méretkorlátja alatt marad.',
        'file_extensions' => 'Ez a fájl nem tűnik támogatott kivonatexportnak. Húzz ide egy banki CSV-t, MT940-et (.sta / .mt940 / .txt), CAMT.053 XML-t, kártyakivonatot PDF-ben, e-mail-üzenetet (.eml) vagy postafiók-archívumot (.mbox).',
        'issuer_format' => 'A(z) :attribute értéke nem érvényes a(z) :source forráshoz.',
        'process_failed' => 'Ezt a fájlt nem sikerült feldolgozni (:class). A teljes hiba a /dev/logs alatt található.',
    ],
];
