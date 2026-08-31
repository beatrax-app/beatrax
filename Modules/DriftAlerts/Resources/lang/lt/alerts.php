<?php

declare(strict_types=1);

return [
    'page_title' => 'Pokyčio įspėjimai',
    'intro_anomaly' => 'Pavieniai mokėjimai, kurie tau atrodo neįprasti.',
    'intro_drift' => 'Patvirtintos pasikartojančių mokėjimų serijos, kurių naujausias mokėjimas peržengė tavo ribą.',
    'adjust_threshold' => 'Keisti ribą →',
    'adjust_sensitivity' => 'Keisti jautrumą →',

    'type_aria' => 'Įspėjimo tipas',
    'type' => [
        'drift' => 'Prenumeratų pokytis',
        'anomaly' => 'Neįprasti mokėjimai',
    ],

    'lifecycle_aria' => 'Įspėjimo būsena',
    'tabs' => [
        'open' => 'Neperžiūrėti',
        'history' => 'Istorija',
        'dismissed' => 'Paslėpti',
    ],

    'load_more' => 'Įkelti daugiau',
    'group_count' => ':count neperžiūrėtas pokytis|:count neperžiūrėti pokyčiai|:count neperžiūrėtų pokyčių',

    'anomaly_empty' => [
        'open_heading' => 'Neįprastų mokėjimų nėra',
        'open_body' => 'Beatrax stebi tavo išlaidas ir pažymi mokėjimus, kurie atrodo neįprasti. Kai atsiras kas nors neįprasto, tai bus rodoma čia.',
        'history_heading' => 'Kol kas patvirtintų mokėjimų nėra',
        'history_body' => 'Patvirtinti mokėjimai bus rodomi čia, kad matytum, ką jau peržiūrėjai.',
        'dismissed_heading' => 'Kol kas nieko nepaslėpta',
        'dismissed_body' => 'Kai pažymėsi mokėjimą kaip tikėtiną, jis atsiras čia kartu su savo slopinimo taisykle.',
    ],

    'empty_open' => [
        'heading' => 'Neperžiūrėtų pokyčio įspėjimų nėra',
        'body' => 'Beatrax stebi tavo patvirtintas pasikartojančių mokėjimų serijas ir pažymi tas, kurių naujausias mokėjimas nuo ankstesnės sumos skiriasi daugiau nei tavo riba. Ribą keisk čia:',
        'link' => 'Nustatymai → Numatytasis pokyčio įspėjimas',
    ],
    'empty_history' => [
        'heading' => 'Kol kas patvirtintų pokyčių nėra',
        'body' => 'Patvirtinti pokyčio įspėjimai bus rodomi čia, kad matytum, ką jau peržiūrėjai.',
    ],
    'empty_dismissed' => [
        'heading' => 'Kol kas nieko nepaslėpta',
        'body' => 'Kai Beatrax pasakysi, kad seriją nutraukei, tas sprendimas atsiras čia su laiko žyma.',
    ],

    'row' => [
        'per_year' => '/m.',
        'meta_prior_now' => 'anksčiau :prior → dabar :now',
        'meta_detected' => 'aptikta :date',
        'meta_threshold' => 'riba ±:percent %',
        'meta_eur_equiv' => '(≈ :amount/m.)',
        'cancel_impact' => 'Nutrauk tai → sutaupysi :amount/m.',
        'cadence_flipped' => 'Dažnumas pasikeitė — taip pat rodoma čia:',
        'cadence_flipped_link' => 'Peržiūrėti pasikartojančius',
        'acknowledge' => 'Patvirtinti',
        'acknowledge_aria' => 'Patvirtinti pokyčio įspėjimą :id',
        'snooze' => 'Atidėti ▾',
        'snooze_1w' => '1 savaitė',
        'snooze_1m' => '1 mėnuo',
        'snooze_3m' => '3 mėnesiai',
        'model_cancel' => 'Modeliuoti nutraukimą ↗',
        'model_cancel_aria' => 'Modeliuoti nutraukimą — prognozėje modeliuoja pokyčio įspėjimo :id nutraukimą',
        'cancelled' => 'Aš tai nutraukiau',
        'cancelled_aria' => 'Aš tai nutraukiau — paslepia pokyčio įspėjimą :id kaip nutrauktą',
    ],

    'toasts' => [
        'gone' => 'Šio įspėjimo nebėra.',
        'acknowledged' => 'Patvirtinta',
        'snoozed' => 'Atidėta',
        'dismissed' => 'Paslėpta',
        'suppression_added' => 'Slopinimo taisyklė pridėta — anuliuoti',
        'dismissed_expected' => 'Paslėpta kaip tikėtina',
        'reopened' => 'Atverta iš naujo',
        'dismissed_cancelled' => 'Paslėpta kaip nutraukta',
    ],
];
