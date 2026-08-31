<?php

declare(strict_types=1);

return [
    'page_title' => 'Alerte de abatere',
    'intro_anomaly' => 'Plăți individuale care par ieșite din comun pentru tine.',
    'intro_drift' => 'Serii recurente aprobate a căror ultimă plată a ieșit din pragul tău.',
    'adjust_threshold' => 'Ajustează pragul →',
    'adjust_sensitivity' => 'Ajustează sensibilitatea →',

    'type_aria' => 'Tip de alertă',
    'type' => [
        'drift' => 'Abatere la abonamente',
        'anomaly' => 'Tranzacții neobișnuite',
    ],

    'lifecycle_aria' => 'Ciclul de viață al alertei',
    'tabs' => [
        'open' => 'Deschise',
        'history' => 'Istoric',
        'dismissed' => 'Închise',
    ],

    'load_more' => 'Încarcă mai multe',
    'group_count' => ':count abatere deschisă|:count abateri deschise|:count de abateri deschise',

    'anomaly_empty' => [
        'open_heading' => 'Nicio tranzacție neobișnuită',
        'open_body' => 'Beatrax îți urmărește cheltuielile și semnalează plățile care par ieșite din comun. Când apare ceva neobișnuit, îl vezi aici.',
        'history_heading' => 'Nicio plată confirmată deocamdată',
        'history_body' => 'Plățile pe care le-ai confirmat apar aici, ca să vezi ce ai verificat deja.',
        'dismissed_heading' => 'Nimic închis deocamdată',
        'dismissed_body' => 'Când marchezi o plată drept așteptată, aceasta ajunge aici împreună cu regula ei de suprimare.',
    ],

    'empty_open' => [
        'heading' => 'Nicio alertă de abatere deschisă',
        'body' => 'Beatrax îți urmărește seriile recurente aprobate și le semnalează pe cele a căror ultimă plată diferă de suma anterioară cu mai mult decât pragul tău. Ajustează pragul în',
        'link' => 'Setări → Alertă de abatere implicită',
    ],
    'empty_history' => [
        'heading' => 'Nicio abatere confirmată deocamdată',
        'body' => 'Alertele de abatere confirmate apar aici, ca să vezi ce ai verificat deja.',
    ],
    'empty_dismissed' => [
        'heading' => 'Nimic închis deocamdată',
        'body' => 'Când îi spui lui Beatrax că ai anulat o serie, decizia ajunge aici cu marcaj de timp.',
    ],

    'row' => [
        'per_year' => '/an',
        'meta_prior_now' => 'anterior :prior → acum :now',
        'meta_detected' => 'detectat :date',
        'meta_threshold' => 'prag ±:percent %',
        'meta_eur_equiv' => '(≈ :amount/an)',
        'cancel_impact' => 'Anulează → economisești :amount/an',
        'cadence_flipped' => 'Frecvența s-a schimbat — apare și în',
        'cadence_flipped_link' => 'Verifică recurentele',
        'acknowledge' => 'Confirmă',
        'acknowledge_aria' => 'Confirmă alerta de abatere :id',
        'snooze' => 'Amână ▾',
        'snooze_1w' => '1 săptămână',
        'snooze_1m' => '1 lună',
        'snooze_3m' => '3 luni',
        'model_cancel' => 'Simulează anularea ↗',
        'model_cancel_aria' => 'Simulează anularea — modelează anularea în previziune pentru alerta de abatere :id',
        'cancelled' => 'Am anulat acest abonament',
        'cancelled_aria' => 'Am anulat acest abonament — închide alerta de abatere :id ca anulată',
    ],

    'toasts' => [
        'gone' => 'Această alertă nu mai există.',
        'acknowledged' => 'Confirmată',
        'snoozed' => 'Amânată',
        'dismissed' => 'Închisă',
        'suppression_added' => 'Regulă de suprimare adăugată — Anulează',
        'dismissed_expected' => 'Închisă ca așteptată',
        'reopened' => 'Redeschisă',
        'dismissed_cancelled' => 'Închisă ca anulată',
    ],
];
