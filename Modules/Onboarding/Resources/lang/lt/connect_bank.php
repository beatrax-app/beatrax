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
    'drop_lead_csv_layout' => 'Vilk :layout CSV failą čia',
    'drop_lead_pick_bank' => 'Pasirink, kuris bankas eksportavo tavo CSV — tai žinodami perskaitysime jį teisingai.',
    'drop_lead_default' => 'Vilk išrašo failą čia',
    'browse_file' => 'arba pasirink failą',

    'format_help_camt053' => 'CAMT.053 – tai XML formato išrašas. Ieškok jo internetinėje bankininkystėje tarp išrašų arba atsisiuntimų.',
    'format_help_mt940' => 'MT940 – tai grynojo teksto išrašas, siūlomas kaip .sta arba .940 šalia XML ir CSV atsisiuntimų.',
    'format_help_csv' => 'CSV – tai skaičiuoklės eksportas. Kiekvienas bankas stulpelius rikiuoja savaip, todėl pasirink tinkamą išdėstymą. Jei tavojo sąraše nėra, paprašyk banko CAMT.053 arba MT940.',

    'account_name_default' => 'Banko sąskaita',
    'account_name_layout' => ':layout sąskaita',

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
