<?php

declare(strict_types=1);

return [
    'page_title' => 'Protistrana',
    'fallback_account' => 'Účet',
    'fallback_counterparty' => 'Protistrana',

    'edit_display_name' => 'Upravit zobrazované jméno',

    'hero_net_received' => 'Čistě přijato',
    'hero_12mo_total' => 'Součet za 12 měsíců',
    'hero_transactions' => 'Transakce',
    'hero_first_seen' => 'První výskyt',

    'tabs' => [
        'overview' => 'Přehled',
        'transactions' => 'Transakce',
        'chains' => 'Řetězce',
        'aliases' => 'Aliasy',
        'transfers' => 'Převody',
        'entries' => 'Záznamy',
        'payments' => 'Platby',
        'tax_years' => 'Daňové roky',
    ],

    'tablist_aria' => 'Sekce protistrany',

    'tab_note_personal' => '— u osobních kontaktů žádné řetězce financování',
    'tab_note_bank' => '— protistrana pro bankovní poplatky řetězce financování netvoří',
    'tab_note_bank_institution' => '— u institucionálních protistran žádné řetězce financování',
    'tab_note_government' => '— u úřadů žádné řetězce financování',

    'recent_activity' => 'Nedávná aktivita',
    'recurring' => 'Opakované',
    'uncategorized' => 'Bez kategorie',
    'no_recent_transactions' => 'U této protistrany zatím nejsou žádné transakce.',
    'see_all' => 'Zobrazit vše (:count) →',

    'bank' => [
        'fees_heading' => 'Bankovní poplatky podle kategorie',
        'activity_heading' => 'Aktivita podle kategorie',
        'no_fees' => 'U této protistrany zatím nejsou zaznamenané žádné poplatky.',
    ],

    'government' => [
        'intro' => 'Roční rozpis za všechny roky s aktivitou. Aktuální rok je zvýrazněný.',
        'no_payments' => 'U této protistrany zatím nejsou zaznamenané žádné platby.',
    ],

    'merchant' => [
        'categories' => 'Kategorie',

        'categories_empty_html' => 'Zatím žádné kategorie — transakce bez kategorie najdeš v sekci <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizace</a>.',
        'no_recurring' => 'Nebyly zjištěny žádné opakované vzorce.',
        'per_month_suffix' => '/měs.',
        'funding_chain' => 'Řetězec financování',
        'no_funding_chain' => 'Zatím nebyl zjištěn žádný řetězec financování. K vyhodnocení řetězce je potřeba import dat z ASN + PayPal.',
        'open_chains' => 'Otevřít kontrolu řetězců →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Přidat štítek',
        'no_recurring' => 'Nezjištěno žádné opakování — soukromé převody málokdy drží pevný rytmus; i pravidelné dělení nájmu může měnit data.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Tato protistrana zatím nemá označení',
        'not_labelled_body' => 'Označování neznámých protistran pomáhá Přehledu ukazovat přesné měsíční součty a řetězce financování.',
        'label_cta' => 'Označit tuto protistranu',
    ],

    'support' => [
        'contact_help' => 'Kontakt a pomoc',
        'sign_in_apply' => 'Přihlášení · žádost',
        'your_rights' => 'Tvá práva · námitka',
        'cancel' => 'Vypovědět',
        'help_support' => 'Nápověda a podpora',
        'cheaper_plan' => 'Levnější tarif',
        'aria_gov' => 'Kde získat pomoc',
        'aria_merchant' => 'Podpora a výpověď',
        'heading_gov' => 'Kde získat pomoc',
        'heading_merchant' => 'Podpora a výpověď',
        'cancel_by_email' => 'Vypovědět e-mailem',
    ],
];
