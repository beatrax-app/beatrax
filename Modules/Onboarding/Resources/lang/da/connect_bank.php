<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Din bank',
    'h1' => 'Hent et kontoudtog, og slip det nedenfor',
    'lede' => 'Vælg det format, du fik fra din bank, og slip filen. Vi genkender automatisk CAMT.053 og MT940.',

    'format_group_aria' => 'Format for kontoudtog',
    'got_it_as' => 'Du fik det som:',
    'badge_recommended' => 'anbefales',

    'mini' => [
        'login_label' => 'Log ind',
        'login_sub' => 'Din banks hjemmeside',
        'statements_label' => 'Åbn kontoudtog',
        'statements_sub' => 'I din banks menu',
        'range_label' => 'Vælg en periode',
        'range_sub' => 'Seneste 90 dage',
        'download_label' => 'Hent',
    ],

    'csv_picker_aria' => 'Hvilken bank eksporterede din CSV?',
    'csv_picker_from' => 'Fra:',

    'drop_lead_camt053' => 'Slip din CAMT.053-fil her',
    'drop_lead_mt940' => 'Slip din MT940-fil her',
    'drop_lead_csv_layout' => 'Slip din :layout-CSV her',
    'drop_lead_pick_bank' => 'Vælg, hvilken bank der eksporterede din CSV — det skal vi vide for at læse den korrekt.',
    'drop_lead_default' => 'Slip din kontoudtogsfil her',
    'browse_file' => 'eller vælg en fil',

    'format_help_camt053' => 'CAMT.053 er et kontoudtog i XML — find det i netbanken under kontoudtog eller downloads.',
    'format_help_mt940' => 'MT940 er et kontoudtog i ren tekst, som ligger som .sta eller .940 ved siden af XML og CSV.',
    'format_help_csv' => 'CSV er regnearkseksporten. Hver bank ordner kolonnerne forskelligt, så vælg det layout, din fil passer til. Er dit ikke på listen, så bed banken om CAMT.053 eller MT940 i stedet.',

    'account_name_default' => 'Bankkonto',
    'account_name_layout' => ':layout-konto',

    'file_ready' => '· ✓ klar',

    'skip' => 'Spring dette trin over',
    'continue' => 'Fortsæt →',

    'errors' => [
        'file_required' => 'Slip først din kontoudtogsfil i feltet.',
        'file_max' => 'Filen er for stor. Slip et kontoudtog under 10 MB.',
        'file_extensions' => 'Filen ligner ikke et kontoudtog. Slip en CAMT.053-XML-, CSV- eller MT940-fil.',
        'pick_bank' => 'Vælg, hvilken bank der eksporterede din CSV, før du fortsætter.',
        'unreadable' => 'Kunne ikke læse filen. Hele fejlen står i /dev/logs.',
    ],
];
