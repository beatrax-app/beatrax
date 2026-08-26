<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budžeti',
        'subtitle' => 'Sadaliet visu — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Iepriekšējais periods',
        'next_aria' => 'Nākamais periods',
    ],

    'ready' => [
        'label' => 'Pieejams sadalei',
        'overassigned' => 'Piešķirts vairāk, nekā jums ir — samaziniet kādu aploksni vai gaidiet nākamos ieņēmumus.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Vēl nekas nav piešķirts',
        'copy_hint' => 'Kopējiet pagājušā mēneša plānu vai noklikšķiniet uz šūnas zemāk, lai sāktu piešķirt.',
        'first_hint' => 'Noklikšķiniet uz šūnas zemāk, lai sāktu piešķirt pirmajā mēnesī.',
        'copy_button' => 'Kopēt pagājušo mēnesi',
    ],

    'no_categories' => [
        'heading' => 'Vēl nav izdevumu kategoriju',
        'body' => 'Pievienojiet izdevumu kategoriju, lai sāktu tai piešķirt naudu.',
    ],

    'table' => [
        'category' => 'Kategorija',
        'assigned' => 'Piešķirts',
        'spent' => 'Iztērēts',
        'available' => 'Pieejams',
        'if_overspent' => 'Ja pārtērēts',
        'notify_at' => 'Paziņot pie',
        'actions' => 'Darbības',
    ],

    'badge' => [
        'carries_negative' => 'Pārnes mīnusu',
        'unconverted_aria' => 'Izdevumi valūtā bez pieejama kursa šeit netiek ieskaitīti — skati paneli',
        'unconverted_title' => 'Izdevumi bez pieejama kursa šeit netiek ieskaitīti — skati paneli',
        'over_budget' => 'Pārsniedz budžetu: :count',
    ],

    'row' => [
        'assigned_aria' => 'Piešķirts kategorijai :category',
        'overspend_aria' => 'Ja kategorija :category ir pārtērēta',
        'notify_aria' => 'Paziņot man pie izlietoto procentu skaita kategorijai :category',
        'move_money' => 'Pārvietot naudu',
        'move' => 'Pārvietot',
    ],

    'overspend' => [
        'reduce' => 'Samazināt nākamā mēneša pieejamo sadalei',
        'carry' => 'Pārnest mīnusu šajā aploksnē',
    ],

    'history' => [
        'show' => 'Rādīt vēsturi ↓',
        'hide' => 'Slēpt vēsturi ↑',
        'moved_from' => 'Pārvietots no :category',
        'moved_to' => 'Pārvietots uz :category',
        'undo' => 'Atsaukt',
    ],

    'phone' => [
        'spent' => 'Iztērēts :amount',
        'available' => 'Pieejams :amount',
        'notify_at' => 'Paziņot pie',
    ],

    'modal' => [
        'move_from' => 'Pārvietot no :name',
        'move_from_fallback' => 'aploksnes',
        'move_to' => 'Pārvietot uz',
        'no_other' => 'Citu aplokšņu nav',
        'select' => 'Izvēlieties aploksni',
        'amount' => 'Summa',
        'available_in' => 'Pieejams aploksnē :name: :amount',
        'note' => 'Piezīme (neobligāti)',
        'note_placeholder' => 'piem. Sedz ēdināšanas pārtēriņu',
        'cancel' => 'Atcelt',
        'move_funds' => 'Pārvietot līdzekļus',
    ],

    'glance' => [
        'see_all' => 'Skatīt visu →',
    ],

    'notices' => [
        'invalid_amount' => 'Ievadiet derīgu summu.',
        'threshold_range' => 'Ievadiet veselu skaitli no 1 līdz 200.',
        'copied_last_month' => 'Pagājušā mēneša plāns nokopēts.',
        'choose_envelope' => 'Izvēlieties aploksni, uz kuru pārvietot naudu.',
        'amount_positive' => 'Ievadiet summu, kas lielāka par nulli.',
        'move_failed' => 'Pārvietošanu neizdevās pabeigt — mēģiniet vēlreiz.',
        'money_moved' => 'Nauda pārvietota.',
        'move_undone' => 'Pārvietošana atsaukta.',
    ],

    'errors' => [
        'assigned_negative' => 'Piešķirtā summa nevar būt negatīva.',
        'invalid_overspend_mode' => 'Nederīgs pārtēriņa režīms.',
        'threshold_range' => 'Paziņojuma slieksnim jābūt no 1 līdz 200.',
        'same_envelope' => 'Sākuma un mērķa aploksnei jāatšķiras.',
        'non_positive_amount' => 'Nederīga vai nepozitīva summa.',
        'category_not_found' => 'Kategorija nav atrasta vai lietotājam nav pieejama.',
    ],
];
