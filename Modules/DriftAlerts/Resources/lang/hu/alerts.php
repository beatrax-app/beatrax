<?php

declare(strict_types=1);

return [
    'page_title' => 'Riasztások',
    'heading' => 'Riasztások',
    'intro_anomaly' => 'Egyedi terhelések, amelyek szokatlannak tűnnek nálad.',
    'intro_drift' => 'Jóváhagyott ismétlődő sorozatok, amelyek legutóbbi terhelése kilépett a küszöbértékedből.',
    'adjust_threshold' => 'Küszöbérték módosítása →',
    'adjust_sensitivity' => 'Érzékenység módosítása →',

    'type_aria' => 'Riasztás típusa',
    'type' => [
        'drift' => 'Előfizetés-eltérés',
        'anomaly' => 'Szokatlan terhelések',
    ],

    'lifecycle_aria' => 'Riasztás életciklusa',
    'tabs' => [
        'open' => 'Nyitott',
        'history' => 'Előzmények',
        'dismissed' => 'Elvetve',
    ],

    'load_more' => 'Továbbiak betöltése',
    'group_count' => ':count nyitott eltérés|:count nyitott eltérés',

    'anomaly_empty' => [
        'open_heading' => 'Nincs szokatlan terhelés',
        'open_body' => 'A Beatrax figyeli a költéseidet, és megjelöli a szokatlannak tűnő terheléseket. Ha valami rendhagyó érkezik, itt jelenik meg.',
        'history_heading' => 'Még nincs tudomásul vett terhelés',
        'history_body' => 'A tudomásul vett terhelések itt jelennek meg, hogy lásd, mit tekintettél már át.',
        'dismissed_heading' => 'Még nincs elvetett tétel',
        'dismissed_body' => 'Ha egy terhelést várt tételként jelölsz meg, az ide kerül az elnyomási szabályával együtt.',
    ],

    'empty_open' => [
        'heading' => 'Nincs nyitott eltérésriasztás',
        'body' => 'A Beatrax figyeli a jóváhagyott ismétlődő sorozataidat, és megjelöli azokat, amelyek legutóbbi terhelése a küszöbértéknél jobban tér el a korábbi összegtől. A küszöbértéket itt módosíthatod:',
        'link' => 'Beállítások → Alapértelmezett eltérésriasztás',
    ],
    'empty_history' => [
        'heading' => 'Még nincs tudomásul vett eltérés',
        'body' => 'A tudomásul vett eltérésriasztások itt jelennek meg, hogy lásd, mit tekintettél már át.',
    ],
    'empty_dismissed' => [
        'heading' => 'Még nincs elvetett tétel',
        'body' => 'Ha jelzed a Beatraxnak, hogy lemondtál egy sorozatot, a döntés időbélyeggel ide kerül.',
    ],

    'row' => [
        'per_year' => '/év',
        'meta_prior_now' => 'korábbi :prior → most :now',
        'meta_detected' => 'észlelve: :date',
        'meta_threshold' => 'küszöbérték ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/év)',
        'cancel_impact' => 'Mondd le → :amount/év megtakarítás',
        'cadence_flipped' => 'A gyakoriság megváltozott — itt is megjelenik:',
        'cadence_flipped_link' => 'Ismétlődők áttekintése',
        'acknowledge' => 'Tudomásul vétel',
        'acknowledge_aria' => 'A(z) :id eltérésriasztás tudomásul vétele',
        'snooze' => 'Halasztás ▾',
        'snooze_1w' => '1 hét',
        'snooze_1m' => '1 hónap',
        'snooze_3m' => '3 hónap',
        'model_cancel' => 'Lemondás modellezése ↗',
        'model_cancel_aria' => 'Lemondás modellezése — az előrejelzésben modellezi a(z) :id eltérésriasztás lemondását',
        'cancelled' => 'Ezt lemondtam',
        'cancelled_aria' => 'Ezt lemondtam — a(z) :id eltérésriasztás elvetése lemondottként',
    ],

    'toasts' => [
        'acknowledged' => 'Tudomásul véve',
        'snoozed' => 'Elhalasztva',
        'dismissed' => 'Elvetve',
        'suppression_added' => 'Elnyomási szabály hozzáadva — Visszavonás',
        'dismissed_expected' => 'Elvetve várt tételként',
        'reopened' => 'Újranyitva',
        'dismissed_cancelled' => 'Elvetve lemondottként',
    ],
];
