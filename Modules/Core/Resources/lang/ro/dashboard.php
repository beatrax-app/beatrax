<?php

declare(strict_types=1);

return [
    'page_title' => 'Tablou de bord',
    'subtitle' => 'Perioada aceasta pe scurt.',

    'previous_period' => 'Perioada anterioară',
    'today' => 'Astăzi',
    'next_period' => 'Perioada următoare',

    'totals_aria' => 'Totaluri pentru această perioadă',
    'totals_aria_currency' => 'Totaluri pentru această perioadă — :currency',
    'in' => 'Intrări',
    'out' => 'Ieșiri',
    'net' => 'Net',

    'status_tiles_aria' => 'Carduri de stare',
    'email_scan_health' => 'Starea scanării e-mailului — :count căsuță poștală conectată|Starea scanării e-mailului — :count căsuțe poștale conectate|Starea scanării e-mailului — :count de căsuțe poștale conectate',

    'top_spending' => 'Cele mai mari cheltuieli',
    'no_expenses' => 'Încă nu există cheltuieli categorisite.',
    'top_spending_refunded' => 'În afara clasamentului — :amount s-a întors',

    'recent_transactions' => 'Tranzacții recente',
    'view_all' => 'Vezi toate',
    'nothing_period' => 'Nimic pentru această perioadă.',
    'th_date' => 'Dată',
    'th_counterparty' => 'Contraparte',
    'th_category' => 'Categorie',
    'th_amount' => 'Sumă',
    'uncategorized' => 'Necategorizat',

    'jump_to_records' => [
        'body' => 'Nimic pentru această perioadă. Cele mai recente tranzacții sunt încă aici.',
        'action' => 'Arată perioada :period',
    ],

    'reauth' => [
        'title' => 'O căsuță poștală trebuie reconectată.',
        'body' => 'Una sau mai multe căsuțe poștale au fost deconectate — Beatrax nu le poate scana până nu le reconectezi.',
        'link' => 'Mergi la Căsuțe poștale',
        'dismiss' => 'Închide',
    ],

    'failed_chain' => [
        'title' => 'Rezolvarea lanțurilor a eșuat.',
        'body' => 'Una sau mai multe sarcini de rezolvare a lanțurilor au întâmpinat o eroare.',
        'link' => 'Deschide inspectorul de coadă',
    ],
];
