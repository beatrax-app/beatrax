<?php

declare(strict_types=1);

return [
    'page_title' => 'Motpart',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Motpart',

    'edit_display_name' => 'Rediger visningsnavn',

    'hero_net_received' => 'Netto mottatt',
    'hero_12mo_total' => 'Totalt 12 måneder',
    'hero_transactions' => 'Transaksjoner',
    'hero_first_seen' => 'Først sett',

    'tabs' => [
        'overview' => 'Oversikt',
        'transactions' => 'Transaksjoner',
        'chains' => 'Kjeder',
        'aliases' => 'Aliaser',
        'transfers' => 'Overføringer',
        'entries' => 'Posteringer',
        'payments' => 'Betalinger',
        'tax_years' => 'Inntektsår',
    ],

    'tablist_aria' => 'Motpartens seksjoner',

    'tab_note_personal' => '— ingen finansieringskjeder for private kontakter',
    'tab_note_bank' => '— en motpart for bankgebyrer genererer ikke finansieringskjeder',
    'tab_note_bank_institution' => '— ingen finansieringskjeder for institusjonelle motparter',
    'tab_note_government' => '— ingen finansieringskjeder for offentlige motparter',

    'recent_activity' => 'Siste aktivitet',
    'recurring' => 'Gjentakende',
    'uncategorized' => 'Ikke kategorisert',
    'no_recent_transactions' => 'Ingen transaksjoner registrert for denne motparten ennå.',
    'see_all' => 'Se alle :count →',

    'bank' => [
        'fees_heading' => 'Bankgebyrer per kategori',
        'activity_heading' => 'Aktivitet per kategori',
        'no_fees' => 'Ingen gebyrer registrert på denne motparten ennå.',
    ],

    'government' => [
        'intro' => 'Årlig fordeling på tvers av alle år med aktivitet. Inneværende år er fremhevet.',
        'no_payments' => 'Ingen betalinger registrert for denne motparten ennå.',
    ],

    'merchant' => [
        'categories' => 'Kategorier',

        'categories_empty_html' => 'Ingen kategorier ennå — ikke kategoriserte transaksjoner vises i <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorisering</a>.',
        'no_recurring' => 'Ingen gjentakende mønstre oppdaget.',
        'per_month_suffix' => '/mnd',
        'funding_chain' => 'Finansieringskjede',
        'no_funding_chain' => 'Ingen finansieringskjede er oppdaget ennå. Import av data fra ASN + PayPal kreves for å løse finansieringskjeder.',
        'open_chains' => 'Åpne gjennomgang av kjeder →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Legg til tagg',
        'no_recurring' => 'Ingen gjentakende mønstre oppdaget — private overføringer følger sjelden et fast intervall; selv faste husleiedelinger kan skifte dato.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Denne motparten er ikke merket ennå',
        'not_labelled_body' => 'Å merke ukjente motparter hjelper oversikten med å vise riktige månedssummer og finansieringskjeder.',
        'label_cta' => 'Merk denne motparten',
    ],

    'support' => [
        'contact_help' => 'Kontakt & hjelp',
        'sign_in_apply' => 'Logg inn · søk',
        'your_rights' => 'Rettighetene dine · gjør innsigelse',
        'cancel' => 'Si opp',
        'help_support' => 'Hjelp & support',
        'cheaper_plan' => 'Billigere abonnement',
        'aria_gov' => 'Få hjelp',
        'aria_merchant' => 'Support og oppsigelse',
        'heading_gov' => 'Få hjelp',
        'heading_merchant' => 'Support & oppsigelse',
        'cancel_by_email' => 'Si opp via e-post',
    ],
];
