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
    'drop_lead_asn' => 'Slip din ASN-CSV her',
    'drop_lead_ing' => 'Slip din ING-CSV her',
    'drop_lead_pick_bank' => 'Vælg, hvilken bank der eksporterede din CSV — det skal vi vide for at læse den korrekt.',
    'drop_lead_default' => 'Slip din kontoudtogsfil her',
    'browse_file' => 'eller vælg en fil',

    'banks_mt940' => 'Understøttet: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Understøttet: ASN, ING — flere formater kommer, efterhånden som brugere bidrager med eksempelfiler.',
    'banks_default' => 'Understøttet: ASN, ING',

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
