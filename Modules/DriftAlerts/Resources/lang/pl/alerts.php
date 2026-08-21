<?php

declare(strict_types=1);

return [
    'page_title' => 'Alerty',
    'heading' => 'Alerty',
    'intro_anomaly' => 'Pojedyncze obciążenia, które wyglądają nietypowo na tle Twoich wydatków.',
    'intro_drift' => 'Zatwierdzone serie cykliczne, w których ostatnie obciążenie wyszło poza próg.',
    'adjust_threshold' => 'Dostosuj próg →',
    'adjust_sensitivity' => 'Dostosuj czułość →',

    'type_aria' => 'Typ alertu',
    'type' => [
        'drift' => 'Odchylenie subskrypcji',
        'anomaly' => 'Nietypowe obciążenia',
    ],

    'lifecycle_aria' => 'Cykl życia alertu',
    'tabs' => [
        'open' => 'Otwarte',
        'history' => 'Historia',
        'dismissed' => 'Odrzucone',
    ],

    'load_more' => 'Wczytaj więcej',
    'group_count' => 'Otwarte odchylenia: :count',

    'anomaly_empty' => [
        'open_heading' => 'Brak nietypowych obciążeń',
        'open_body' => 'Beatrax obserwuje Twoje wydatki i oznacza obciążenia, które wyglądają nietypowo. Gdy pojawi się coś nietypowego, zobaczysz to tutaj.',
        'history_heading' => 'Brak potwierdzonych obciążeń',
        'history_body' => 'Potwierdzone obciążenia pojawią się tutaj, aby było widać, co zostało już przejrzane.',
        'dismissed_heading' => 'Nic jeszcze nie odrzucono',
        'dismissed_body' => 'Gdy oznaczysz obciążenie jako oczekiwane, trafi tutaj wraz z regułą wyciszenia.',
    ],

    'empty_open' => [
        'heading' => 'Brak otwartych alertów o odchyleniu',
        'body' => 'Beatrax obserwuje zatwierdzone serie cykliczne i oznacza te, w których ostatnie obciążenie różni się od poprzedniej kwoty bardziej niż o ustawiony próg. Próg dostosujesz w',
        'link' => 'Ustawienia → Domyślny alert o odchyleniu',
    ],
    'empty_history' => [
        'heading' => 'Brak potwierdzonych odchyleń',
        'body' => 'Potwierdzone alerty o odchyleniu pojawią się tutaj, aby było widać, co zostało już przejrzane.',
    ],
    'empty_dismissed' => [
        'heading' => 'Nic jeszcze nie odrzucono',
        'body' => 'Gdy zaznaczysz w Beatrax, że seria została anulowana, ta decyzja trafi tutaj wraz ze znacznikiem czasu.',
    ],

    'row' => [
        'per_year' => '/rok',
        'meta_prior_now' => 'poprzednio :prior → teraz :now',
        'meta_detected' => 'wykryto :date',
        'meta_threshold' => 'próg ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/rok)',
        'cancel_impact' => 'Anuluj → oszczędność :amount/rok',
        'cadence_flipped' => 'Zmiana częstotliwości — widoczne także w',
        'cadence_flipped_link' => 'Przegląd cyklicznych',
        'acknowledge' => 'Potwierdź',
        'acknowledge_aria' => 'Potwierdź alert o odchyleniu :id',
        'snooze' => 'Odłóż ▾',
        'snooze_1w' => '1 tydzień',
        'snooze_1m' => '1 miesiąc',
        'snooze_3m' => '3 miesiące',
        'model_cancel' => 'Symuluj anulowanie ↗',
        'model_cancel_aria' => 'Symuluj anulowanie — uwzględnia anulowanie w prognozie dla alertu o odchyleniu :id',
        'cancelled' => 'Już to anulowano',
        'cancelled_aria' => 'Już to anulowano — odrzuca alert o odchyleniu :id jako anulowany',
    ],

    'toasts' => [
        'acknowledged' => 'Potwierdzono',
        'snoozed' => 'Odłożono',
        'dismissed' => 'Odrzucono',
        'suppression_added' => 'Dodano regułę wyciszenia — Cofnij',
        'dismissed_expected' => 'Odrzucono jako oczekiwane',
        'reopened' => 'Otwarto ponownie',
        'dismissed_cancelled' => 'Odrzucono jako anulowane',
    ],
];
