<?php

declare(strict_types=1);

return [
    'heading' => 'Log',
    'subtitle' => 'Tail live del file di log Laravel di oggi, con doppio oscuramento dei dati sia in scrittura sia in streaming.',
    'truncate' => 'Svuota',
    'truncate_confirm' => 'Svuotare il file di log di oggi? Questa azione non è reversibile.',
    'truncate_title' => "Svuota il file di log di oggi (mantiene l'inode così il tailer riprende senza problemi)",
    'filters_aria' => 'Filtri dei log',
    'severity_aria' => 'Filtro per gravità',
    'channel_placeholder' => 'Filtro per canale…',
    'channel_aria' => 'Filtro per canale',
    'contains_placeholder' => 'Cerca tra i visibili…',
    'contains_aria' => 'Filtro contiene',
    'pause' => 'Pausa',
    'resume' => 'Riprendi',
    'waiting' => 'In attesa di righe di log…',
    'copy' => 'Copia',
    'copy_title' => 'Copia la voce completa',
    'copy_title_copied' => 'Copiato',
    'copy_aria' => 'Copia la voce di log',
    'copy_aria_copied' => 'Copiato negli appunti',
    'dismiss' => 'Ignora',
    'dismiss_title' => 'Nascondi dalla vista (non modifica il file di log)',
    'dismiss_aria' => 'Nascondi la voce di log dalla vista',
    'totals' => [
        'showing' => 'Visualizzazione di :shown su :count riga ricevuta (buffer max :cap)|Visualizzazione di :shown su :count righe ricevute (buffer max :cap)',
        'lines_today' => ':count riga oggi|:count righe oggi',
        'lines_today_capped' => 'oltre :count riga oggi|oltre :count righe oggi',
        'today' => 'oggi',
        'all_files' => ':size su :count file giornaliero|:size su :count file giornalieri',
    ],

    'status' => [
        'poll_interrupted' => 'Polling dei log interrotto. Nuovo tentativo…',
        'paused' => 'In pausa.',
        'copy_failed_prefix' => 'Copia non riuscita: ',
        'clipboard_unavailable' => 'appunti non disponibili',
    ],

    'toast' => [
        'truncated' => 'Log svuotato — liberati :size.',
        'nothing' => 'Niente da svuotare.',
    ],
];
