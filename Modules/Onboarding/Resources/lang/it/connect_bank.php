<?php

declare(strict_types=1);

return [
    'eyebrow' => 'La tua banca',
    'h1' => 'Scarica un estratto conto, poi trascinalo qui sotto',
    'lede' => 'Scegli il formato che ti ha dato la banca, poi trascina il file. Rileviamo automaticamente CAMT.053 e MT940.',

    'format_group_aria' => 'Formato estratto conto bancario',
    'got_it_as' => 'Ottenuto come:',
    'badge_recommended' => 'consigliato',

    'mini' => [
        'login_label' => 'Accedi',
        'login_sub' => 'Il sito della tua banca',
        'statements_label' => 'Apri gli estratti conto',
        'statements_sub' => 'Nel menu della tua banca',
        'range_label' => 'Scegli un periodo',
        'range_sub' => 'Ultimi 90 giorni',
        'download_label' => 'Scarica',
    ],

    'csv_picker_aria' => 'Quale banca ha esportato il tuo CSV?',
    'csv_picker_from' => 'Da:',

    'drop_lead_camt053' => 'Trascina qui il tuo file CAMT.053',
    'drop_lead_mt940' => 'Trascina qui il tuo file MT940',
    'drop_lead_csv_layout' => 'Trascina qui il tuo CSV di :layout',
    'drop_lead_pick_bank' => 'Scegli quale banca ha esportato il tuo CSV — ci serve saperlo per leggerlo correttamente.',
    'drop_lead_default' => 'Trascina qui il file del tuo estratto conto',
    'browse_file' => 'oppure cerca un file',

    'format_help_camt053' => 'CAMT.053 è un estratto conto in XML: cercalo nella tua banca online, tra estratti conto o download.',
    'format_help_mt940' => 'MT940 è un estratto conto in testo semplice, offerto come .sta o .940 accanto ai download XML e CSV.',
    'format_help_csv' => 'CSV è l’esportazione per fogli di calcolo. Ogni banca ordina le colonne a modo suo, quindi scegli il layout corrispondente. Se il tuo non è in elenco, chiedi alla tua banca un CAMT.053 o un MT940.',

    'account_name_default' => 'Conto bancario',
    'account_name_layout' => 'Conto :layout',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Salta questo passaggio',
    'continue' => 'Continua →',

    'errors' => [
        'file_required' => 'Trascina prima il file del tuo estratto conto nel riquadro.',
        'file_max' => 'Questo file è troppo grande. Trascina un estratto conto sotto i 10 MB.',
        'file_extensions' => 'Questo file non sembra un estratto conto bancario. Trascina un file XML CAMT.053, CSV o MT940.',
        'pick_bank' => 'Scegli quale banca ha esportato il tuo CSV prima di continuare.',
        'unreadable' => "Non è stato possibile leggere questo file. L'errore completo è in /dev/logs.",
    ],
];
