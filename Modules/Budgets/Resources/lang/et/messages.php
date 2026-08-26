<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Eelarved',
        'subtitle' => 'Jaga kõik ära — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Eelmine periood',
        'next_aria' => 'Järgmine periood',
    ],

    'ready' => [
        'label' => 'Jagamiseks valmis',
        'overassigned' => 'Oled jaganud rohkem, kui sul on — vähenda mõnda ümbrikku või oota lisatulu.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Midagi pole veel jagatud',
        'copy_hint' => 'Kopeeri eelmise kuu plaan või klõpsa allolevale lahtrile, et jagamist alustada.',
        'first_hint' => 'Klõpsa allolevale lahtrile, et alustada esimese kuu jagamist.',
        'copy_button' => 'Kopeeri eelmine kuu',
    ],

    'no_categories' => [
        'heading' => 'Kulukategooriaid veel pole',
        'body' => 'Lisa kulukategooria, et hakata sellele raha jagama.',
    ],

    'table' => [
        'category' => 'Kategooria',
        'assigned' => 'Jagatud',
        'spent' => 'Kulutatud',
        'available' => 'Saadaval',
        'if_overspent' => 'Ülekulu korral',
        'notify_at' => 'Teavita',
        'actions' => 'Toimingud',
    ],

    'badge' => [
        'carries_negative' => 'Kannab miinust edasi',
        'unconverted_aria' => 'Kulu valuutas, millele kurssi pole, siin ei arvestata — vaata töölauda',
        'unconverted_title' => 'Kurssita kulu siin ei arvestata — vaata töölauda',
        'over_budget' => ':count üle eelarve',
    ],

    'row' => [
        'assigned_aria' => 'Kategooriale :category jagatud',
        'overspend_aria' => 'Kui kategooria :category on ülekulutatud',
        'notify_aria' => 'Teavita mind kategooria :category kasutusprotsendi juures',
        'move_money' => 'Liiguta raha',
        'move' => 'Liiguta',
    ],

    'overspend' => [
        'reduce' => 'Vähenda järgmise kuu jagamiseks valmis summat',
        'carry' => 'Kanna miinus selles ümbrikus edasi',
    ],

    'history' => [
        'show' => 'Näita ajalugu ↓',
        'hide' => 'Peida ajalugu ↑',
        'moved_from' => 'Liigutatud kategooriast :category',
        'moved_to' => 'Liigutatud kategooriasse :category',
        'undo' => 'Võta tagasi',
    ],

    'phone' => [
        'spent' => 'Kulutatud :amount',
        'available' => 'Saadaval :amount',
        'notify_at' => 'Teavita',
    ],

    'modal' => [
        'move_from' => 'Liiguta ümbrikust :name',
        'move_from_fallback' => 'ümbrik',
        'move_to' => 'Kuhu liigutada',
        'no_other' => 'Teisi ümbrikuid pole',
        'select' => 'Vali ümbrik',
        'amount' => 'Summa',
        'available_in' => 'Ümbrikus :name saadaval: :amount',
        'note' => 'Märkus (valikuline)',
        'note_placeholder' => 'nt Katan söögikohtade ülekulu',
        'cancel' => 'Tühista',
        'move_funds' => 'Liiguta raha',
    ],

    'glance' => [
        'see_all' => 'Vaata kõiki →',
    ],

    'notices' => [
        'invalid_amount' => 'Sisesta kehtiv summa.',
        'threshold_range' => 'Sisesta täisarv vahemikus 1 kuni 200.',
        'copied_last_month' => 'Eelmise kuu plaan on kopeeritud.',
        'choose_envelope' => 'Vali ümbrik, kuhu raha liigutada.',
        'amount_positive' => 'Sisesta nullist suurem summa.',
        'move_failed' => 'Liigutamine ebaõnnestus — proovi uuesti.',
        'money_moved' => 'Raha on liigutatud.',
        'move_undone' => 'Liigutamine on tagasi võetud.',
    ],

    'errors' => [
        'assigned_negative' => 'Jagatud summa ei saa olla negatiivne.',
        'invalid_overspend_mode' => 'Vigane ülekulu režiim.',
        'threshold_range' => 'Teavituse lävi peab olema vahemikus 1 kuni 200.',
        'same_envelope' => 'Lähte- ja sihtümbrik peavad olema erinevad.',
        'non_positive_amount' => 'Vigane või mittepositiivne summa.',
        'category_not_found' => 'Kategooriat ei leitud või pole see kasutajale kättesaadav.',
    ],
];
