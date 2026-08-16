<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Velkommen',
        'heading' => 'Velkommen til Beatrax',
        'subtitle' => 'Dit rent lokale økonomioverblik er klar. Opret din første konto for at komme i gang.',
        'get_started' => 'Kom i gang',
    ],

    'setup' => [
        'page_title' => 'Sætter op…',
        'pending_heading' => 'Sætter op…',
        'pending_body' => 'Beatrax forbereder dine data. Det tager kun et øjeblik.',
        'failed_body' => 'Opsætningen kunne ikke gennemføres. Genstart Beatrax; hvis den bliver ved med at fejle, står årsagen i loggen.',
        'ready_heading' => 'Klar',
        'ready_body' => 'Opsætningen er færdig. Fortsætter…',
    ],

    'staging' => [
        'page_title' => 'Fil modtaget',
        'heading_prefix' => 'Fil modtaget: ',
        'button_label' => 'Start importen',
        'csv_subtitle' => 'En bank- eller PayPal-eksport — start importen for at se en forhåndsvisning og bekræfte.',
        'eml_subtitle' => 'En e-mailkvittering — start importen for at vedhæfte den til dens transaktion.',
        'empty_heading' => 'Vi kunne ikke åbne den fil',
        'empty_body' => 'Beatrax kunne ikke læse den fil, du åbnede. Prøv i stedet at importere den fra Import-siden.',
        'open_imports' => 'Åbn Import',
    ],

    'close' => [
        'title' => 'Skal Beatrax blive ved med at køre?',
        'body' => 'Når du lukker vinduet, kan Beatrax enten afsluttes helt eller blive kørende diskret i menulinjen, så planlagte e-mailscanninger fortsætter.',
        'button_quit' => 'Afslut Beatrax',
        'button_keep_in_tray' => 'Bliv ved med at køre i menulinjen',
        'checkbox_remember' => 'Husk mit valg',
    ],
];
