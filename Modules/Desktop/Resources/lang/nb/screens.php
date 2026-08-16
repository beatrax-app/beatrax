<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Velkommen',
        'heading' => 'Velkommen til Beatrax',
        'subtitle' => 'Den helt lokale økonomioversikten din er klar. Opprett den første kontoen din for å komme i gang.',
        'get_started' => 'Kom i gang',
    ],

    'setup' => [
        'page_title' => 'Setter opp…',
        'pending_heading' => 'Setter opp…',
        'pending_body' => 'Beatrax forbereder dataene dine. Det tar bare et øyeblikk.',
        'failed_body' => 'Oppsettet kunne ikke fullføres. Start Beatrax på nytt; hvis det fortsetter å feile, står årsaken i loggen.',
        'ready_heading' => 'Klar',
        'ready_body' => 'Oppsettet er fullført. Fortsetter…',
    ],

    'staging' => [
        'page_title' => 'Fil mottatt',
        'heading_prefix' => 'Fil mottatt: ',
        'button_label' => 'Start importen',
        'csv_subtitle' => 'En bank- eller PayPal-eksport — start importen for å forhåndsvise og bekrefte.',
        'eml_subtitle' => 'En e-postkvittering — start importen for å knytte den til transaksjonen sin.',
        'empty_heading' => 'Vi kunne ikke åpne den filen',
        'empty_body' => 'Beatrax kunne ikke lese filen du åpnet. Prøv heller å importere den fra Import-siden.',
        'open_imports' => 'Åpne Import',
    ],

    'close' => [
        'title' => 'Vil du la Beatrax fortsette å kjøre?',
        'body' => 'Når du lukker vinduet, kan Beatrax enten avsluttes helt eller fortsette å kjøre diskret i menylinjen slik at planlagte e-postskanninger fortsetter.',
        'button_quit' => 'Avslutt Beatrax',
        'button_keep_in_tray' => 'Fortsett å kjøre i menylinjen',
        'checkbox_remember' => 'Husk valget mitt',
    ],
];
