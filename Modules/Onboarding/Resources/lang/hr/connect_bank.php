<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja banka',
    'h1' => 'Preuzmi izvod pa ga ispusti ispod',
    'lede' => 'Odaberi format koji ti je banka dala pa ispusti datoteku. CAMT.053 i MT940 prepoznajemo automatski.',

    'format_group_aria' => 'Format bankovnog izvoda',
    'got_it_as' => 'Preuzeto kao:',
    'badge_recommended' => 'preporučeno',

    'mini' => [
        'login_label' => 'Prijavi se',
        'login_sub' => 'Mrežna stranica tvoje banke',
        'statements_label' => 'Otvori izvode',
        'statements_sub' => 'U izborniku tvoje banke',
        'range_label' => 'Odaberi razdoblje',
        'range_sub' => 'Zadnjih 90 dana',
        'download_label' => 'Preuzmi',
    ],

    'csv_picker_aria' => 'Koja je banka izvezla tvoj CSV?',
    'csv_picker_from' => 'Iz:',

    'drop_lead_camt053' => 'Ovdje ispusti svoju CAMT.053 datoteku',
    'drop_lead_mt940' => 'Ovdje ispusti svoju MT940 datoteku',
    'drop_lead_csv_layout' => 'Ovdje ispusti svoj :layout CSV',
    'drop_lead_pick_bank' => 'Odaberi koja je banka izvezla tvoj CSV — moramo to znati da bismo ga ispravno pročitali.',
    'drop_lead_default' => 'Ovdje ispusti datoteku izvoda',
    'browse_file' => 'ili potraži datoteku',

    'format_help_camt053' => 'CAMT.053 je izvod u XML-u — potraži ga u internetskom bankarstvu pod izvodima ili preuzimanjima.',
    'format_help_mt940' => 'MT940 je izvod u običnom tekstu, nudi se kao .sta ili .940 uz XML i CSV preuzimanja.',
    'format_help_csv' => 'CSV je izvoz za proračunske tablice. Svaka banka drukčije slaže stupce, pa odaberi raspored koji odgovara. Ako tvoj nije na popisu, zatraži od banke CAMT.053 ili MT940.',

    'account_name_default' => 'Bankovni račun',
    'account_name_layout' => 'Račun :layout',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'file_required' => 'Najprije ispusti datoteku izvoda u okvir.',
        'file_max' => 'Ta datoteka je prevelika. Ispusti izvod manji od 10 MB.',
        'file_extensions' => 'Ta datoteka ne izgleda kao bankovni izvod. Ispusti CAMT.053 XML, CSV ili MT940 datoteku.',
        'pick_bank' => 'Prije nastavka odaberi koja je banka izvezla tvoj CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cijela pogreška je u /dev/logs.',
    ],
];
