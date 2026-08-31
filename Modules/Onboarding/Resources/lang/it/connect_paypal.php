<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Il tuo account PayPal',
    'h1' => 'Collega il tuo account PayPal',

    'lede_html' => 'Trascina qui il tuo export dei movimenti PayPal — una riga per transazione, non il riepilogo dei saldi. PayPal nomina i suoi rapporti nella lingua del tuo account, e per ora leggiamo la coppia olandese: <em lang="nl">Rapport Transactiegegevens</em>, non <span lang="nl">Saldorapport</span>. Se il tuo esce in un’altra lingua, imposta PayPal su olandese prima di scaricarlo.',

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

    'drop_lead' => 'Trascina qui il tuo export dei movimenti',
    'browse_file' => 'oppure cerca un file',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Salta questo passaggio',
    'continue' => 'Continua →',

    'errors' => [
        'required' => 'Trascina prima nel riquadro il tuo export dei movimenti PayPal.',
        'max' => 'Questo file è troppo grande. Un export dei movimenti PayPal sta di solito ben sotto i 10 MB.',
        'extensions' => 'Questo file non sembra un CSV di PayPal. Scarica l’export dei movimenti — una riga per transazione, non il riepilogo dei saldi — in formato CSV.',
        'unreadable' => "Non è stato possibile leggere questo file. L'errore completo è in /dev/logs.",
    ],
];
