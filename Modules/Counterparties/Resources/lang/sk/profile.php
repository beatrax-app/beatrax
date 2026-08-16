<?php

declare(strict_types=1);

return [
    'page_title' => 'Protistrana',
    'fallback_account' => 'Účet',
    'fallback_counterparty' => 'Protistrana',

    'edit_display_name' => 'Upraviť zobrazované meno',

    'hero_net_received' => 'Čisté prijaté',
    'hero_12mo_total' => 'Súčet za 12 mesiacov',
    'hero_transactions' => 'Transakcie',
    'hero_first_seen' => 'Prvýkrát zaznamenané',

    'tabs' => [
        'overview' => 'Prehľad',
        'transactions' => 'Transakcie',
        'chains' => 'Reťazce',
        'aliases' => 'Aliasy',
        'transfers' => 'Prevody',
        'entries' => 'Záznamy',
        'payments' => 'Platby',
        'tax_years' => 'Daňové roky',
    ],

    'tab_note_personal' => '— pri osobných kontaktoch nie sú finančné reťazce',
    'tab_note_bank' => '— protistrana s bankovými poplatkami negeneruje finančné reťazce',
    'tab_note_government' => '— pri štátnych protistranách nie sú finančné reťazce',

    'recent_activity' => 'Nedávna aktivita',
    'recurring' => 'Opakované',
    'uncategorized' => 'Bez kategórie',
    'no_recent_transactions' => 'Pre túto protistranu zatiaľ nie sú žiadne transakcie.',
    'see_all' => 'Zobraziť všetko (:count) →',

    'bank' => [
        'fees_heading' => 'Bankové poplatky podľa kategórie',
        'no_fees' => 'Pri tejto protistrane zatiaľ nie sú zaznamenané žiadne poplatky.',
    ],

    'government' => [
        'intro' => 'Ročný rozpis za všetky roky s aktivitou. Aktuálny rok je zvýraznený.',
        'no_payments' => 'Pri tejto protistrane zatiaľ nie sú zaznamenané žiadne platby.',
    ],

    'merchant' => [
        'categories' => 'Kategórie',

        'categories_empty_html' => 'Zatiaľ žiadne kategórie — nezaradené transakcie nájdeš v sekcii <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizácia</a>.',
        'no_recurring' => 'Nezistili sa žiadne opakované vzory.',
        'per_month_suffix' => '/mes.',
        'funding_chain' => 'Finančný reťazec',
        'no_funding_chain' => 'Zatiaľ sa nezistil žiadny finančný reťazec. Na jeho vyhodnotenie sú potrebné importy údajov z ASN + PayPal.',
        'open_chains' => 'Otvoriť kontrolu reťazcov →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Pridať štítok',
        'no_recurring' => 'Nezistilo sa nič opakované — osobné prevody málokedy držia presný rytmus; aj pravidelné delenie nájmu sa v dátumoch posúva.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Táto protistrana zatiaľ nie je označená',
        'not_labelled_body' => 'Označovanie neznámych pomáha Prehľadu ukazovať presné mesačné súčty a finančné reťazce.',
        'label_cta' => 'Označiť túto protistranu',
    ],

    'support' => [
        'contact_help' => 'Kontakt a pomoc',
        'sign_in_apply' => 'Prihlásenie · žiadosť',
        'your_rights' => 'Tvoje práva · námietka',
        'cancel' => 'Zrušiť',
        'help_support' => 'Pomoc a podpora',
        'cheaper_plan' => 'Lacnejší program',
        'aria_gov' => 'Ako získať pomoc',
        'aria_merchant' => 'Podpora a rušenie',
        'heading_gov' => 'Ako získať pomoc',
        'heading_merchant' => 'Podpora a rušenie',
        'cancel_by_email' => 'Zrušiť e-mailom',
    ],
];
