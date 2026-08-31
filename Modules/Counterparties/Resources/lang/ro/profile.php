<?php

declare(strict_types=1);

return [
    'page_title' => 'Contraparte',
    'fallback_account' => 'Cont',
    'fallback_counterparty' => 'Contraparte',

    'edit_display_name' => 'Editează numele afișat',

    'hero_net_received' => 'Net încasat',
    'hero_12mo_total' => 'Total pe 12 luni',
    'hero_transactions' => 'Tranzacții',
    'hero_first_seen' => 'Prima apariție',

    'tabs' => [
        'overview' => 'Prezentare generală',
        'transactions' => 'Tranzacții',
        'chains' => 'Lanțuri',
        'aliases' => 'Aliasuri',
        'transfers' => 'Transferuri',
        'entries' => 'Intrări',
        'payments' => 'Plăți',
        'tax_years' => 'Ani fiscali',
    ],

    'tablist_aria' => 'Secțiunile contrapărții',

    'tab_note_personal' => '— fără lanțuri de finanțare pentru contactele personale',
    'tab_note_bank' => '— o contraparte de comisioane bancare nu generează lanțuri de finanțare',
    'tab_note_bank_institution' => '— fără lanțuri de finanțare pentru contrapărțile instituționale',
    'tab_note_government' => '— fără lanțuri de finanțare pentru instituțiile publice',

    'recent_activity' => 'Activitate recentă',
    'recurring' => 'Recurente',
    'uncategorized' => 'Necategorizate',
    'no_recent_transactions' => 'Nicio tranzacție înregistrată pentru această contraparte deocamdată.',
    'see_all' => 'Vezi toate cele :count →',

    'bank' => [
        'fees_heading' => 'Comisioane bancare pe categorii',
        'activity_heading' => 'Activitate pe categorii',
        'no_fees' => 'Niciun comision înregistrat pentru această contraparte deocamdată.',
    ],

    'government' => [
        'intro' => 'Defalcare anuală pentru toți anii cu activitate. Anul curent este evidențiat.',
        'no_payments' => 'Nicio plată înregistrată deocamdată pentru această contraparte.',
    ],

    'merchant' => [
        'categories' => 'Categorii',

        'categories_empty_html' => 'Nicio categorie deocamdată — tranzacțiile necategorizate apar în <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorisire</a>.',
        'no_recurring' => 'Niciun tipar recurent detectat.',
        'per_month_suffix' => '/lună',
        'funding_chain' => 'Lanț de finanțare',
        'no_funding_chain' => 'Niciun lanț de finanțare detectat încă. Rezolvarea lanțurilor de finanțare necesită importuri de date ASN + PayPal.',
        'open_chains' => 'Deschide verificarea lanțurilor →',
    ],

    'personal' => [
        'contact' => 'Contact',
        'add_tag' => '+ Adaugă etichetă',
        'no_recurring' => 'Nicio recurență detectată — transferurile personale rareori respectă o frecvență strictă; chiar și împărțirea regulată a chiriei își poate muta datele.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Această contraparte nu este încă etichetată',
        'not_labelled_body' => 'Etichetarea necunoscutelor ajută tabloul de bord să afișeze totaluri lunare corecte și lanțuri de finanțare.',
        'label_cta' => 'Etichetează această contraparte',
    ],

    'support' => [
        'contact_help' => 'Contact și ajutor',
        'sign_in_apply' => 'Autentificare · solicitare',
        'your_rights' => 'Drepturile tale · contestare',
        'cancel' => 'Anulare',
        'help_support' => 'Ajutor și asistență',
        'cheaper_plan' => 'Plan mai ieftin',
        'aria_gov' => 'Cum obții ajutor',
        'aria_merchant' => 'Asistență și anulare',
        'heading_gov' => 'Cum obții ajutor',
        'heading_merchant' => 'Asistență și anulare',
        'cancel_by_email' => 'Anulare prin e-mail',
    ],
];
