<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Vítej',
        'heading' => 'Vítej v aplikaci Beatrax',
        'subtitle' => 'Tvůj čistě lokální finanční přehled je připravený. Začni vytvořením prvního účtu.',
        'get_started' => 'Začít',
    ],

    'setup' => [
        'page_title' => 'Nastavuje se…',
        'pending_heading' => 'Nastavuje se…',
        'pending_body' => 'Beatrax připravuje tvá data. Bude to jen chvilka.',
        'failed_body' => 'Nastavení se nepodařilo dokončit. Restartuj Beatrax; pokud selhává dál, důvod najdeš v logu.',
        'ready_heading' => 'Hotovo',
        'ready_body' => 'Nastavení dokončeno. Pokračuje se…',
    ],

    'staging' => [
        'page_title' => 'Soubor přijat',
        'heading_prefix' => 'Soubor přijat: ',
        'button_label' => 'Spustit import',
        'csv_subtitle' => 'Export z banky nebo z PayPalu — spusť import, uvidíš náhled a potvrdíš ho.',
        'eml_subtitle' => 'Účtenka z e-mailu — spusť import a připojí se ke své transakci.',
        'empty_heading' => 'Tenhle soubor se nepodařilo otevřít',
        'empty_body' => 'Beatrax nedokázal otevřený soubor přečíst. Zkus ho místo toho naimportovat ze stránky Importy.',
        'open_imports' => 'Otevřít Importy',
    ],

    'close' => [
        'title' => 'Nechat Beatrax běžet?',
        'body' => 'Zavření okna může Beatrax buď úplně ukončit, nebo ho nechat tiše běžet v liště nabídek, aby plánované skenování e-mailů pokračovalo.',
        'button_quit' => 'Ukončit Beatrax',
        'button_keep_in_tray' => 'Nechat běžet v liště',
        'checkbox_remember' => 'Zapamatovat si moji volbu',
    ],
];
