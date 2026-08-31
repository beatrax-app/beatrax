<?php

declare(strict_types=1);

return [
    'heading' => 'Logid',
    'subtitle' => 'Tänase Laraveli logifaili reaalajas jälgimine koos topeltkindlustusega: tundlik info varjatakse nii kirjutamisel kui ka voogedastusel.',
    'truncate' => 'Tühjenda',
    'truncate_confirm' => 'Kas tühjendada tänane logifail? Seda ei saa tagasi võtta.',
    'truncate_title' => 'Tühjenda tänane logifail (säilitab inode’i, nii et jälgija jätkab puhtalt)',
    'filters_aria' => 'Logifiltrid',
    'severity_aria' => 'Raskusastme filter',
    'channel_placeholder' => 'Kanali filter…',
    'channel_aria' => 'Kanali filter',
    'contains_placeholder' => 'Otsi nähtavast…',
    'contains_aria' => 'Sisaldab-filter',
    'pause' => 'Peata',
    'resume' => 'Jätka',
    'waiting' => 'Ootan logiridu…',
    'copy' => 'Kopeeri',
    'copy_title' => 'Kopeeri kogu kirje',
    'copy_title_copied' => 'Kopeeritud',
    'copy_aria' => 'Kopeeri logikirje',
    'copy_aria_copied' => 'Kopeeritud lõikelauale',
    'dismiss' => 'Peida',
    'dismiss_title' => 'Peida vaatest (logifaili ei muudeta)',
    'dismiss_aria' => 'Peida logikirje vaatest',
    'totals' => [
        'showing' => 'Kuvatud :shown / :count vastu võetud reast (puhvri ülempiir :cap)|Kuvatud :shown / :count vastu võetud reast (puhvri ülempiir :cap)',
        'lines_today' => ':count rida täna|:count rida täna',
        'lines_today_capped' => 'üle :count rea täna|üle :count rea täna',
        'today' => 'täna',
        'all_files' => ':size kokku :count päevafailis|:size kokku :count päevafailis',
    ],

    'status' => [
        'poll_interrupted' => 'Logi pärimine katkes. Proovin uuesti…',
        'paused' => 'Peatatud.',
        'copy_failed_prefix' => 'Kopeerimine ebaõnnestus: ',
        'clipboard_unavailable' => 'lõikelaud pole saadaval',
    ],

    'toast' => [
        'truncated' => 'Logi on tühjendatud — vabanes :size.',
        'nothing' => 'Pole midagi tühjendada.',
    ],
];
