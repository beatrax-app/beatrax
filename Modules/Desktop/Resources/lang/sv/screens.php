<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Välkommen',
        'heading' => 'Välkommen till Beatrax',
        'subtitle' => 'Din helt lokala ekonomiöversikt är klar. Skapa ditt första konto för att komma igång.',
        'get_started' => 'Kom igång',
    ],

    'setup' => [
        'page_title' => 'Konfigurerar…',
        'pending_heading' => 'Konfigurerar…',
        'pending_body' => 'Beatrax förbereder dina data. Det tar bara ett ögonblick.',
        'failed_body' => 'Konfigurationen kunde inte slutföras. Starta om Beatrax; om det fortsätter att gå fel finns orsaken i loggen.',
        'ready_heading' => 'Klar',
        'ready_body' => 'Konfigurationen är klar. Fortsätter…',
    ],

    'staging' => [
        'page_title' => 'Fil mottagen',
        'heading_prefix' => 'Fil mottagen: ',
        'button_label' => 'Starta importen',
        'csv_subtitle' => 'En bank- eller PayPal-export — starta importen för att förhandsgranska och bekräfta.',
        'eml_subtitle' => 'Ett e-postkvitto — starta importen för att koppla det till sin transaktion.',
        'empty_heading' => 'Vi kunde inte öppna filen',
        'empty_body' => 'Beatrax kunde inte läsa filen du öppnade. Försök att importera den från Import-sidan i stället.',
        'open_imports' => 'Öppna Import',
    ],

    'close' => [
        'title' => 'Vill du låta Beatrax fortsätta köra?',
        'body' => 'När du stänger fönstret kan Beatrax antingen avslutas helt eller fortsätta köra diskret i menyraden så att schemalagda e-postskanningar fortsätter.',
        'button_quit' => 'Avsluta Beatrax',
        'button_keep_in_tray' => 'Fortsätt köra i menyraden',
        'checkbox_remember' => 'Kom ihåg mitt val',
    ],
];
