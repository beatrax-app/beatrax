<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Il tuo account PayPal',
    'h1' => 'Collega il tuo account PayPal',

    'lede_html' => 'Trascina qui il tuo export PayPal con i dettagli delle transazioni — in un account PayPal olandese si chiama <em lang="nl">Rapport Transactiegegevens</em>. Il rapporto sui saldi (<span lang="nl">Saldorapport</span>) non funziona — ci servono i dati per singolo evento.',

    'format_group_aria' => 'PayPal esporta solo in CSV',
    'got_it_as' => 'Ottenuto come:',
    'badge_only_format' => 'unico formato',

    'mini' => [
        'login_label' => 'Accedi',
        'custom_label' => 'Estratti conto personalizzati',
        'range_label' => 'Scegli un periodo',
        'range_sub' => 'Ultimi 12 mesi',
        'download_label' => 'Scarica in CSV',
    ],

    'drop_lead' => 'Trascina qui il CSV con i dettagli delle transazioni',
    'browse_file' => 'oppure cerca un file',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Salta questo passaggio',
    'continue' => 'Continua →',

    'errors' => [
        'required' => 'Trascina prima nel riquadro il tuo CSV PayPal Rapport Transactiegegevens.',
        'max' => 'Questo file è troppo grande. Gli export PayPal Rapport Transactiegegevens stanno di solito ben sotto i 10 MB.',
        'extensions' => 'Questo file non sembra un CSV di PayPal. Scarica da PayPal il Rapport Transactiegegevens (non il rapporto sui saldi Saldorapport) in formato CSV.',
        'unreadable' => "Non è stato possibile leggere questo file. L'errore completo è in /dev/logs.",
    ],
];
