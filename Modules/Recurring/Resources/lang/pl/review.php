<?php

declare(strict_types=1);

return [
    'title' => 'Przegląd cyklicznych',
    'subtitle' => 'Zatwierdź, odłóż lub odrzuć wykryte sugestie płatności cyklicznych.',

    'tabs' => [
        'pending' => 'Oczekujące',
        'rejected' => 'Odrzucone',
        'cadence_changed' => 'Zmieniona częstotliwość',
    ],

    'bulk' => [
        'aria' => 'Akcje zbiorcze',
        'selected' => 'wybrano: :count',
        'approve' => 'Zatwierdź (:count)',
        'reject' => 'Odrzuć (:count)',
    ],

    'empty' => [
        'heading' => 'Nie ma nic do przejrzenia',
        'pending' => 'Sugestie cykliczne trafiają tutaj, gdy detektor wykryje stabilne miesięczne skupienia.',
        'rejected' => 'Odrzucone sugestie pojawiają się tutaj, żeby dało się je przywrócić, jeśli zmienisz zdanie.',
        'cadence_changed' => 'Zatwierdzone serie, których częstotliwość się zmieniła, trafiają tutaj do ponownego przeglądu.',
    ],

    'next' => 'Następna',
    'overdue' => 'Po terminie',
    'cadence_changed_note' => 'zmieniona częstotliwość',
    'un_reject' => 'Cofnij odrzucenie',
    'approve' => 'Zatwierdź',
    'approve_aria' => 'Zatwierdź serię cykliczną :id',
    'reject' => 'Odrzuć',
    'reject_aria' => 'Odrzuć serię cykliczną :id',
    'snooze' => 'Odłóż',
    'snooze_aria' => 'Odłóż serię cykliczną :id',
    'snooze_1w' => '1 tydzień',
    'snooze_1m' => '1 miesiąc',
    'snooze_3m' => '3 miesiące',
    'edit_name' => 'Edytuj nazwę',
    'edit_name_aria' => 'Zmień nazwę serii cyklicznej :id',
    'new_name_label' => 'Nowa nazwa dla tej serii',
    'save' => 'Zapisz',

    'toast' => [
        'approved' => 'Zatwierdzono',
        'rejected' => 'Odrzucono',
        'snoozed' => 'Odłożono',
        'renamed' => 'Zmieniono nazwę',
        'un_rejected' => 'Cofnięto odrzucenie',
        'bulk_approved' => 'Zatwierdzono: :count',
        'bulk_rejected' => 'Odrzucono: :count',
    ],
];
