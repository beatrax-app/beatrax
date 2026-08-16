<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Banken din',
    'h1' => 'Hent en kontoutskrift, og slipp den nedenfor',
    'lede' => 'Velg formatet du fikk fra banken, og slipp filen. Vi gjenkjenner CAMT.053 og MT940 automatisk.',

    'format_group_aria' => 'Format for kontoutskrift',
    'got_it_as' => 'Du fikk det som:',
    'badge_recommended' => 'anbefales',

    'mini' => [
        'login_label' => 'Logg inn',
        'login_sub' => 'Nettstedet til banken din',
        'statements_label' => 'Åpne kontoutskrifter',
        'statements_sub' => 'I menyen til banken din',
        'range_label' => 'Velg en periode',
        'range_sub' => 'Siste 90 dager',
        'download_label' => 'Last ned',
    ],

    'csv_picker_aria' => 'Hvilken bank eksporterte CSV-filen din?',
    'csv_picker_from' => 'Fra:',

    'drop_lead_camt053' => 'Slipp CAMT.053-filen din her',
    'drop_lead_mt940' => 'Slipp MT940-filen din her',
    'drop_lead_asn' => 'Slipp ASN-CSV-filen din her',
    'drop_lead_ing' => 'Slipp ING-CSV-filen din her',
    'drop_lead_pick_bank' => 'Velg hvilken bank som eksporterte CSV-filen — det må vi vite for å lese den riktig.',
    'drop_lead_default' => 'Slipp kontoutskriftsfilen din her',
    'browse_file' => 'eller velg en fil',

    'banks_mt940' => 'Støttes: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Støttes: ASN, ING — flere formater kommer etter hvert som brukere bidrar med eksempelfiler.',
    'banks_default' => 'Støttes: ASN, ING',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hopp over dette trinnet',
    'continue' => 'Fortsett →',

    'errors' => [
        'file_required' => 'Slipp kontoutskriftsfilen din i feltet først.',
        'file_max' => 'Filen er for stor. Slipp en kontoutskrift under 10 MB.',
        'file_extensions' => 'Filen ser ikke ut som en kontoutskrift. Slipp en CAMT.053-XML-, CSV- eller MT940-fil.',
        'pick_bank' => 'Velg hvilken bank som eksporterte CSV-filen, før du fortsetter.',
        'unreadable' => 'Kunne ikke lese filen. Hele feilen står i /dev/logs.',
    ],
];
