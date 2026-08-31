<?php

declare(strict_types=1);

return [
    'page_title' => 'Modpart',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Modpart',

    'edit_display_name' => 'Redigér visningsnavn',

    'hero_net_received' => 'Netto modtaget',
    'hero_12mo_total' => 'I alt 12 måneder',
    'hero_transactions' => 'Transaktioner',
    'hero_first_seen' => 'Først set',

    'tabs' => [
        'overview' => 'Overblik',
        'transactions' => 'Transaktioner',
        'chains' => 'Kæder',
        'aliases' => 'Aliasser',
        'transfers' => 'Overførsler',
        'entries' => 'Posteringer',
        'payments' => 'Betalinger',
        'tax_years' => 'Indkomstår',
    ],

    'tablist_aria' => 'Modpartens sektioner',

    'tab_note_personal' => '— ingen finansieringskæder for private kontakter',
    'tab_note_bank' => '— en modpart for bankgebyrer genererer ikke finansieringskæder',
    'tab_note_bank_institution' => '— ingen finansieringskæder for institutionelle modparter',
    'tab_note_government' => '— ingen finansieringskæder for offentlige modparter',

    'recent_activity' => 'Seneste aktivitet',
    'recurring' => 'Tilbagevendende',
    'uncategorized' => 'Ikke kategoriseret',
    'no_recent_transactions' => 'Ingen transaktioner registreret for denne modpart endnu.',
    'see_all' => 'Se alle :count →',

    'bank' => [
        'fees_heading' => 'Bankgebyrer pr. kategori',
        'activity_heading' => 'Aktivitet pr. kategori',
        'no_fees' => 'Ingen gebyrer registreret på denne modpart endnu.',
    ],

    'government' => [
        'intro' => 'Årlig fordeling på tværs af alle år med aktivitet. Det aktuelle år er fremhævet.',
        'no_payments' => 'Ingen betalinger registreret for denne modpart endnu.',
    ],

    'merchant' => [
        'categories' => 'Kategorier',

        'categories_empty_html' => 'Ingen kategorier endnu — ikke kategoriserede transaktioner vises i <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorisering</a>.',
        'no_recurring' => 'Ingen tilbagevendende mønstre fundet.',
        'per_month_suffix' => '/md.',
        'funding_chain' => 'Finansieringskæde',
        'no_funding_chain' => 'Der er endnu ikke fundet nogen finansieringskæde. Import af data fra ASN + PayPal er nødvendig for at kunne løse finansieringskæder.',
        'open_chains' => 'Åbn gennemgang af kæder →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Tilføj tag',
        'no_recurring' => 'Intet tilbagevendende mønster fundet — private overførsler følger sjældent et fast interval; selv regelmæssige delinger af husleje kan skifte dato.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Denne modpart er ikke mærket endnu',
        'not_labelled_body' => 'At mærke ukendte modparter hjælper overblikket med at vise korrekte månedssummer og finansieringskæder.',
        'label_cta' => 'Mærk denne modpart',
    ],

    'support' => [
        'contact_help' => 'Kontakt & hjælp',
        'sign_in_apply' => 'Log ind · ansøg',
        'your_rights' => 'Dine rettigheder · gør indsigelse',
        'cancel' => 'Opsig',
        'help_support' => 'Hjælp & support',
        'cheaper_plan' => 'Billigere abonnement',
        'aria_gov' => 'Få hjælp',
        'aria_merchant' => 'Support og opsigelse',
        'heading_gov' => 'Få hjælp',
        'heading_merchant' => 'Support & opsigelse',
        'cancel_by_email' => 'Opsig via e-mail',
    ],
];
