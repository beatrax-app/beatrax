<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tavo bankas',
    'h1' => 'Parsisiųsk išrašą ir įkelk jį žemiau',
    'lede' => 'Pasirink formatą, kurį pateikė tavo bankas, tada įkelk failą. CAMT.053 ir MT940 atpažįstame automatiškai.',

    'format_group_aria' => 'Banko išrašo formatas',
    'got_it_as' => 'Turiu kaip:',
    'badge_recommended' => 'rekomenduojama',

    'mini' => [
        'login_label' => 'Prisijunk',
        'login_sub' => 'Savo banko svetainėje',
        'statements_label' => 'Atverk išrašus',
        'statements_sub' => 'Savo banko meniu',
        'range_label' => 'Pasirink laikotarpį',
        'range_sub' => 'Paskutinės 90 dienų',
        'download_label' => 'Atsisiųsk',
    ],

    'csv_picker_aria' => 'Kuris bankas eksportavo tavo CSV?',
    'csv_picker_from' => 'Iš:',

    'drop_lead_camt053' => 'Vilk CAMT.053 failą čia',
    'drop_lead_mt940' => 'Vilk MT940 failą čia',
    'drop_lead_asn' => 'Vilk ASN CSV failą čia',
    'drop_lead_ing' => 'Vilk ING CSV failą čia',
    'drop_lead_pick_bank' => 'Pasirink, kuris bankas eksportavo tavo CSV — tai žinodami perskaitysime jį teisingai.',
    'drop_lead_default' => 'Vilk išrašo failą čia',
    'browse_file' => 'arba pasirink failą',

    'banks_mt940' => 'Palaikoma: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Palaikoma: ASN, ING — daugiau formatų atsiras naudotojams atsiuntus pavyzdžių.',
    'banks_default' => 'Palaikoma: ASN, ING',

    'file_ready' => '· ✓ paruošta',

    'skip' => 'Praleisti šį žingsnį',
    'continue' => 'Tęsti →',

    'errors' => [
        'file_required' => 'Pirmiausia įkelk išrašo failą į laukelį.',
        'file_max' => 'Šis failas per didelis. Įkelk mažesnį nei 10 MB išrašą.',
        'file_extensions' => 'Šis failas nepanašus į banko išrašą. Įkelk CAMT.053 XML, CSV arba MT940 failą.',
        'pick_bank' => 'Prieš tęsdamas pasirink, kuris bankas eksportavo tavo CSV.',
        'unreadable' => 'Šio failo perskaityti nepavyko. Visą klaidą rasi /dev/logs.',
    ],
];
