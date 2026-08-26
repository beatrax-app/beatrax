<?php

declare(strict_types=1);

return [
    'page_title' => 'Abweichungswarnungen',
    'heading' => 'Warnungen',
    'intro_anomaly' => 'Einzelne Abbuchungen, die für dich ungewöhnlich aussehen.',
    'intro_drift' => 'Bestätigte wiederkehrende Reihen, deren letzte Abbuchung außerhalb deiner Schwelle liegt.',
    'adjust_threshold' => 'Schwelle anpassen →',
    'adjust_sensitivity' => 'Empfindlichkeit anpassen →',

    'type_aria' => 'Art der Warnung',
    'type' => [
        'drift' => 'Abo-Abweichung',
        'anomaly' => 'Ungewöhnliche Abbuchungen',
    ],

    'lifecycle_aria' => 'Lebenszyklus der Warnung',
    'tabs' => [
        'open' => 'Offen',
        'history' => 'Verlauf',
        'dismissed' => 'Verworfen',
    ],

    'load_more' => 'Mehr laden',
    'group_count' => ':count Abweichung offen|:count Abweichungen offen',

    'anomaly_empty' => [
        'open_heading' => 'Keine ungewöhnlichen Abbuchungen',
        'open_body' => 'Beatrax beobachtet deine Ausgaben und markiert Abbuchungen, die ungewöhnlich aussehen. Sobald etwas Ungewöhnliches auftaucht, erscheint es hier.',
        'history_heading' => 'Noch keine bestätigten Abbuchungen',
        'history_body' => 'Abbuchungen, die du bestätigt hast, erscheinen hier, damit du siehst, was du schon geprüft hast.',
        'dismissed_heading' => 'Noch nichts verworfen',
        'dismissed_body' => 'Wenn du eine Abbuchung als erwartet markierst, landet sie hier mit ihrer Unterdrückungsregel.',
    ],

    'empty_open' => [
        'heading' => 'Keine offenen Abweichungswarnungen',
        'body' => 'Beatrax beobachtet deine bestätigten wiederkehrenden Reihen und markiert jede Reihe, deren letzte Abbuchung um mehr als deine Schwelle vom vorherigen Betrag abweicht. Passe die Schwelle an unter',
        'link' => 'Einstellungen → Standard-Abweichungswarnung',
    ],
    'empty_history' => [
        'heading' => 'Noch keine bestätigten Abweichungen',
        'body' => 'Bestätigte Abweichungswarnungen erscheinen hier, damit du siehst, was du schon geprüft hast.',
    ],
    'empty_dismissed' => [
        'heading' => 'Noch nichts verworfen',
        'body' => 'Wenn du Beatrax mitteilst, dass du eine Reihe gekündigt hast, landet diese Entscheidung hier mit einem Zeitstempel.',
    ],

    'row' => [
        'per_year' => '/Jahr',
        'meta_prior_now' => 'vorher :prior → jetzt :now',
        'meta_detected' => 'erkannt am :date',
        'meta_threshold' => 'Schwelle ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/Jahr)',
        'cancel_impact' => 'Das kündigen → :amount/Jahr sparen',
        'cadence_flipped' => 'Rhythmus geändert — erscheint auch in',
        'cadence_flipped_link' => 'Wiederkehrendes prüfen',
        'acknowledge' => 'Bestätigen',
        'acknowledge_aria' => 'Abweichungswarnung :id bestätigen',
        'snooze' => 'Zurückstellen ▾',
        'snooze_1w' => '1 Woche',
        'snooze_1m' => '1 Monat',
        'snooze_3m' => '3 Monate',
        'model_cancel' => 'Kündigung simulieren ↗',
        'model_cancel_aria' => 'Kündigung simulieren — bildet die Kündigung in der Prognose für Abweichungswarnung :id ab',
        'cancelled' => 'Ich habe das gekündigt',
        'cancelled_aria' => 'Ich habe das gekündigt — verwirft Abweichungswarnung :id als gekündigt',
    ],

    'toasts' => [
        'acknowledged' => 'Bestätigt',
        'snoozed' => 'Zurückgestellt',
        'dismissed' => 'Verworfen',
        'suppression_added' => 'Unterdrückungsregel hinzugefügt — Rückgängig',
        'dismissed_expected' => 'Als erwartet verworfen',
        'reopened' => 'Wieder geöffnet',
        'dismissed_cancelled' => 'Als gekündigt verworfen',
    ],
];
