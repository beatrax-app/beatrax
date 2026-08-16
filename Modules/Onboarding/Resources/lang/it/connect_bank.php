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
    'drop_lead_asn' => 'Trascina qui il tuo CSV di ASN',
    'drop_lead_ing' => 'Trascina qui il tuo CSV di ING',
    'drop_lead_pick_bank' => 'Scegli quale banca ha esportato il tuo CSV — ci serve saperlo per leggerlo correttamente.',
    'drop_lead_default' => 'Trascina qui il file del tuo estratto conto',
    'browse_file' => 'oppure cerca un file',

    'banks_mt940' => 'Supportate: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Supportate: ASN, ING — altri formati in arrivo man mano che gli utenti inviano campioni.',
    'banks_default' => 'Supportate: ASN, ING',

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
