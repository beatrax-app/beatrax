<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Din bank',
    'h1' => 'Hämta ett kontoutdrag och släpp det nedan',
    'lede' => 'Välj det format du fick från din bank och släpp filen. Vi känner igen CAMT.053 och MT940 automatiskt.',

    'format_group_aria' => 'Format för kontoutdrag',
    'got_it_as' => 'Du fick det som:',
    'badge_recommended' => 'rekommenderas',

    'mini' => [
        'login_label' => 'Logga in',
        'login_sub' => 'Din banks webbplats',
        'statements_label' => 'Öppna kontoutdrag',
        'statements_sub' => 'I din banks meny',
        'range_label' => 'Välj en period',
        'range_sub' => 'Senaste 90 dagarna',
        'download_label' => 'Ladda ner',
    ],

    'csv_picker_aria' => 'Vilken bank exporterade din CSV?',
    'csv_picker_from' => 'Från:',

    'drop_lead_camt053' => 'Släpp din CAMT.053-fil här',
    'drop_lead_mt940' => 'Släpp din MT940-fil här',
    'drop_lead_csv_layout' => 'Släpp din :layout-CSV här',
    'drop_lead_pick_bank' => 'Välj vilken bank som exporterade din CSV — vi behöver veta det för att läsa den rätt.',
    'drop_lead_default' => 'Släpp din kontoutdragsfil här',
    'browse_file' => 'eller bläddra efter en fil',

    'format_help_camt053' => 'CAMT.053 är ett kontoutdrag i XML — leta i internetbanken under kontoutdrag eller nedladdningar.',
    'format_help_mt940' => 'MT940 är ett kontoutdrag i ren text, som erbjuds som .sta eller .940 bredvid XML och CSV.',
    'format_help_csv' => 'CSV är kalkylarksexporten. Varje bank ordnar kolumnerna olika, så välj den layout som stämmer. Finns inte din med i listan, be banken om CAMT.053 eller MT940 i stället.',

    'account_name_default' => 'Bankkonto',
    'account_name_layout' => ':layout-konto',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hoppa över det här steget',
    'continue' => 'Fortsätt →',

    'errors' => [
        'file_required' => 'Släpp först din kontoutdragsfil i rutan.',
        'file_max' => 'Filen är för stor. Släpp ett kontoutdrag under 10 MB.',
        'file_extensions' => 'Filen ser inte ut som ett kontoutdrag. Släpp en CAMT.053-XML-, CSV- eller MT940-fil.',
        'pick_bank' => 'Välj vilken bank som exporterade din CSV innan du fortsätter.',
        'unreadable' => 'Kunde inte läsa filen. Hela felet finns i /dev/logs.',
    ],
];
