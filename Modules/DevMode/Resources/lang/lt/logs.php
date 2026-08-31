<?php

declare(strict_types=1);

return [
    'heading' => 'Žurnalai',
    'subtitle' => 'Tiesioginis šios dienos Laravel žurnalo failo sekimas su dviguba apsauga — slapti duomenys slepiami ir rašant, ir siunčiant srautu.',
    'truncate' => 'Sutrumpinti',
    'truncate_confirm' => 'Ištuštinti šios dienos žurnalo failą? Šio veiksmo atšaukti negalima.',
    'truncate_title' => 'Ištuštinti šios dienos žurnalo failą (išsaugo inode, kad sekiklis tęstų sklandžiai)',
    'filters_aria' => 'Žurnalo filtrai',
    'severity_aria' => 'Svarbos filtras',
    'channel_placeholder' => 'Kanalo filtras…',
    'channel_aria' => 'Kanalo filtras',
    'contains_placeholder' => 'Ieškoti tarp matomų…',
    'contains_aria' => 'Turinio filtras',
    'pause' => 'Pristabdyti',
    'resume' => 'Tęsti',
    'waiting' => 'Laukiama žurnalo eilučių…',
    'copy' => 'Kopijuoti',
    'copy_title' => 'Kopijuoti visą įrašą',
    'copy_title_copied' => 'Nukopijuota',
    'copy_aria' => 'Kopijuoti žurnalo įrašą',
    'copy_aria_copied' => 'Nukopijuota į iškarpinę',
    'dismiss' => 'Slėpti',
    'dismiss_title' => 'Paslėpti iš rodinio (žurnalo failas nekeičiamas)',
    'dismiss_aria' => 'Paslėpti žurnalo įrašą iš rodinio',
    'totals' => [
        'showing' => 'Rodoma :shown iš :count gautos eilutės (buferio riba :cap)|Rodoma :shown iš :count gautų eilučių (buferio riba :cap)|Rodoma :shown iš :count gautų eilučių (buferio riba :cap)',
        'lines_today' => ':count eilutė šiandien|:count eilutės šiandien|:count eilučių šiandien',
        'lines_today_capped' => 'daugiau nei :count eilutė šiandien|daugiau nei :count eilutės šiandien|daugiau nei :count eilučių šiandien',
        'today' => 'šiandien',
        'all_files' => ':size iš :count dienos failo|:size iš :count dienos failų|:size iš :count dienos failų',
    ],

    'status' => [
        'poll_interrupted' => 'Žurnalo apklausa nutrūko. Bandoma dar kartą…',
        'paused' => 'Pristabdyta.',
        'copy_failed_prefix' => 'Nepavyko nukopijuoti: ',
        'clipboard_unavailable' => 'iškarpinė nepasiekiama',
    ],

    'toast' => [
        'truncated' => 'Žurnalas sutrumpintas — atlaisvinta :size.',
        'nothing' => 'Nėra ko trumpinti.',
    ],
];
