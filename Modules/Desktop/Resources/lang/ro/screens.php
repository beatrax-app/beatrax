<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Bun venit',
        'heading' => 'Bine ai venit în Beatrax',
        'subtitle' => 'Tabloul tău de bord financiar, strict local, este gata. Creează primul cont ca să începi.',
        'get_started' => 'Începe',
    ],

    'setup' => [
        'page_title' => 'Se configurează…',
        'pending_heading' => 'Se configurează…',
        'pending_body' => 'Beatrax îți pregătește datele. Durează doar o clipă.',
        'failed_body' => 'Configurarea nu s-a putut finaliza. Repornește Beatrax; dacă tot eșuează, motivul se află în jurnal.',
        'ready_heading' => 'Gata',
        'ready_body' => 'Configurare completă. Se continuă…',
    ],

    'staging' => [
        'page_title' => 'Fișier primit',
        'heading_prefix' => 'Fișier primit: ',
        'button_label' => 'Începe importul',
        'csv_subtitle' => 'Un export de la bancă sau PayPal — începe importul ca să previzualizezi și să confirmi.',
        'eml_subtitle' => 'Un bon primit pe e-mail — începe importul ca să îl atașăm la tranzacția sa.',
        'empty_heading' => 'Nu am putut deschide acel fișier',
        'empty_body' => 'Beatrax nu a putut citi fișierul pe care l-ai deschis. Încearcă să îl imporți din pagina Importuri.',
        'open_imports' => 'Deschide Importuri',
    ],

    'close' => [
        'title' => 'Lași Beatrax să ruleze?',
        'body' => 'Închiderea ferestrei poate fie să oprească Beatrax complet, fie să îl lase să ruleze discret în bara de meniu, ca scanările programate de e-mail să continue.',
        'button_quit' => 'Închide Beatrax',
        'button_keep_in_tray' => 'Lasă-l să ruleze în bara de sistem',
        'checkbox_remember' => 'Ține minte alegerea mea',
    ],
];
