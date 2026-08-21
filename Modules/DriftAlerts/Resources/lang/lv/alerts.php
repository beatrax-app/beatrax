<?php

declare(strict_types=1);

return [
    'page_title' => 'Brīdinājumi',
    'heading' => 'Brīdinājumi',
    'intro_anomaly' => 'Atsevišķi maksājumi, kas jums izskatās neierasti.',
    'intro_drift' => 'Apstiprinātās regulāro maksājumu sērijas, kuru jaunākais maksājums pārsniedza jūsu slieksni.',
    'adjust_threshold' => 'Pielāgot slieksni →',
    'adjust_sensitivity' => 'Pielāgot jutīgumu →',

    'type_aria' => 'Brīdinājuma veids',
    'type' => [
        'drift' => 'Abonementu cenu izmaiņas',
        'anomaly' => 'Neparasti maksājumi',
    ],

    'lifecycle_aria' => 'Brīdinājuma dzīves cikls',
    'tabs' => [
        'open' => 'Atvērtie',
        'history' => 'Vēsture',
        'dismissed' => 'Aizvērtie',
    ],

    'load_more' => 'Ielādēt vairāk',
    'group_count' => ':count atvērtu izmaiņu|:count atvērta izmaiņa|:count atvērtas izmaiņas',

    'anomaly_empty' => [
        'open_heading' => 'Nav neparastu maksājumu',
        'open_body' => 'Beatrax vēro jūsu tēriņus un atzīmē maksājumus, kas izskatās neierasti. Kad parādīsies kaut kas neparasts, tas būs redzams šeit.',
        'history_heading' => 'Vēl nav zināšanai pieņemtu maksājumu',
        'history_body' => 'Zināšanai pieņemtie maksājumi parādīsies šeit, lai redzētu, kas jau ir pārskatīts.',
        'dismissed_heading' => 'Vēl nekas nav aizvērts',
        'dismissed_body' => 'Kad atzīmēsiet maksājumu kā gaidītu, tas nonāks šeit kopā ar savu slāpēšanas noteikumu.',
    ],

    'empty_open' => [
        'heading' => 'Nav atvērtu izmaiņu brīdinājumu',
        'body' => 'Beatrax vēro jūsu apstiprinātās regulāro maksājumu sērijas un atzīmē tās, kuru jaunākais maksājums no iepriekšējās summas atšķiras vairāk par jūsu slieksni. Slieksni pielāgojiet sadaļā',
        'link' => 'Iestatījumi → Noklusējuma izmaiņu brīdinājums',
    ],
    'empty_history' => [
        'heading' => 'Vēl nav zināšanai pieņemtu izmaiņu',
        'body' => 'Zināšanai pieņemtie izmaiņu brīdinājumi parādīsies šeit, lai redzētu, kas jau ir pārskatīts.',
    ],
    'empty_dismissed' => [
        'heading' => 'Vēl nekas nav aizvērts',
        'body' => 'Kad paziņosiet Beatrax, ka sērija ir atcelta, šis lēmums nonāks šeit ar laika zīmogu.',
    ],

    'row' => [
        'per_year' => '/gadā',
        'meta_prior_now' => 'iepriekš :prior → tagad :now',
        'meta_detected' => 'atklāts :date',
        'meta_threshold' => 'slieksnis ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/gadā)',
        'cancel_impact' => 'Atsakieties → ietaupiet :amount/gadā',
        'cadence_flipped' => 'Biežums mainījies — redzams arī sadaļā',
        'cadence_flipped_link' => 'Pārskatīt regulāros maksājumus',
        'acknowledge' => 'Pieņemt zināšanai',
        'acknowledge_aria' => 'Pieņemt zināšanai izmaiņu brīdinājumu :id',
        'snooze' => 'Atlikt ▾',
        'snooze_1w' => '1 nedēļa',
        'snooze_1m' => '1 mēnesis',
        'snooze_3m' => '3 mēneši',
        'model_cancel' => 'Modelēt atteikšanos ↗',
        'model_cancel_aria' => 'Modelēt atteikšanos — modelē atteikšanos prognozē izmaiņu brīdinājumam :id',
        'cancelled' => 'Es no tā atteicos',
        'cancelled_aria' => 'Es no tā atteicos — aizver izmaiņu brīdinājumu :id kā atceltu',
    ],

    'toasts' => [
        'acknowledged' => 'Pieņemts zināšanai',
        'snoozed' => 'Atlikts',
        'dismissed' => 'Aizvērts',
        'suppression_added' => 'Slāpēšanas noteikums pievienots — Atsaukt',
        'dismissed_expected' => 'Aizvērts kā gaidīts',
        'reopened' => 'Atvērts atkārtoti',
        'dismissed_cancelled' => 'Aizvērts kā atcelts',
    ],
];
