<?php

declare(strict_types=1);

return [
    'page_title' => 'Peržiūrėti grandines',
    'heading' => 'Peržiūrėti grandines',
    'hint' => ':count užuomina|:count užuominos|:count užuominų',
    'subtitle' => 'Patvirtink arba atmesk kandidatines sąsajas, kurių grandinių sprendiklis negalėjo patvirtinti automatiškai.',

    'empty_heading' => 'Nėra ką peržiūrėti',
    'empty_body' => 'Kiekviena sąsaja, kurią sprendiklis sugebėjo suporuoti, yra patvirtinta arba atmesta. Nauji kandidatai atsiras čia, kai bus importuota naujų duomenų.',

    'auto_confirm_nudge' => 'Dar vienas patvirtinimas ir panašios sąsajos bus patvirtinamos automatiškai.',

    'confirm' => 'Patvirtinti',
    'reject' => 'Atmesti',
    'confirm_aria' => 'Patvirtinti grandinės sąsają :id',
    'reject_aria' => 'Atmesti grandinės sąsają :id',
    'show_more' => 'Rodyti daugiau',

    'kind' => [
        'paypal_funding' => 'PayPal finansavimas',
        'ics_bulk_settle' => 'Bendras iDEAL atsiskaitymas',
    ],

    'errors' => [
        'confirm_hint' => 'Šis kandidatas yra užuomina — atverk jį ir prieš patvirtindamas pridėk atitinkamą operaciją.',
        'reject_hint' => 'Šis kandidatas yra užuomina — atverk jį ir prieš atmesdamas pridėk atitinkamą operaciją.',
    ],
];
