<?php

declare(strict_types=1);

return [
    'page_title' => 'Tegenpartij',
    'fallback_account' => 'Rekening',
    'fallback_counterparty' => 'Tegenpartij',

    'edit_display_name' => 'Weergavenaam bewerken',

    'hero_net_received' => 'Netto ontvangen',
    'hero_12mo_total' => 'Totaal 12 maanden',
    'hero_transactions' => 'Transacties',
    'hero_first_seen' => 'Eerst gezien',

    'tabs' => [
        'overview' => 'Overzicht',
        'transactions' => 'Transacties',
        'chains' => 'Ketens',
        'aliases' => 'Aliassen',
        'transfers' => 'Overboekingen',
        'entries' => 'Boekingen',
        'payments' => 'Betalingen',
        'tax_years' => 'Belastingjaren',
    ],

    'tablist_aria' => 'Secties van de tegenpartij',

    'tab_note_personal' => '— geen financieringsketens voor persoonlijke contacten',
    'tab_note_bank' => '— tegenpartij voor bankkosten genereert geen financieringsketens',
    'tab_note_bank_institution' => '— geen financieringsketens voor institutionele tegenpartijen',
    'tab_note_government' => '— geen financieringsketens voor overheidstegenpartijen',

    'recent_activity' => 'Recente activiteit',
    'recurring' => 'Terugkerend',
    'uncategorized' => 'Niet-gecategoriseerd',
    'no_recent_transactions' => 'Nog geen transacties bekend voor deze tegenpartij.',
    'see_all' => 'Alle :count bekijken →',

    'bank' => [
        'fees_heading' => 'Bankkosten per categorie',
        'activity_heading' => 'Activiteit per categorie',
        'no_fees' => 'Nog geen kosten geregistreerd op deze tegenpartij.',
    ],

    'government' => [
        'intro' => 'Jaarlijks overzicht over alle jaren met activiteit. Het huidige jaar is uitgelicht.',
        'no_payments' => 'Nog geen betalingen geregistreerd voor deze tegenpartij.',
    ],

    'merchant' => [
        'categories' => 'Categorieën',
        'categories_empty_html' => 'Nog geen categorieën — niet-gecategoriseerde transacties verschijnen in <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorisatie</a>.',
        'no_recurring' => 'Geen terugkerende patronen gevonden.',
        'per_month_suffix' => '/mnd',
        'funding_chain' => 'Financieringsketen',
        'no_funding_chain' => 'Nog geen financieringsketen gevonden. Voor het bepalen van de financieringsketen zijn imports van ASN- en PayPal-gegevens nodig.',
        'open_chains' => 'Ketenoverzicht openen →',
    ],

    'personal' => [
        'contact' => 'Contact',
        'add_tag' => '+ Tag toevoegen',
        'no_recurring' => 'Geen terugkerend patroon gevonden — persoonlijke overboekingen volgen zelden een strak ritme; zelfs een vaste huurverdeling kan van datum verschuiven.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Deze tegenpartij is nog niet gelabeld',
        'not_labelled_body' => 'Onbekenden labelen helpt het dashboard nauwkeurige maandtotalen en financieringsketens te tonen.',
        'label_cta' => 'Deze tegenpartij labelen',
    ],

    'support' => [
        'contact_help' => 'Contact & hulp',
        'sign_in_apply' => 'Inloggen · aanvragen',
        'your_rights' => 'Je rechten · bezwaar',
        'cancel' => 'Opzeggen',
        'help_support' => 'Hulp & ondersteuning',
        'cheaper_plan' => 'Goedkoper abonnement',
        'aria_gov' => 'Hulp krijgen',
        'aria_merchant' => 'Ondersteuning en opzeggen',
        'heading_gov' => 'Hulp krijgen',
        'heading_merchant' => 'Ondersteuning & opzeggen',
        'cancel_by_email' => 'Per e-mail opzeggen',
        'withheld' => 'link achtergehouden',
    ],
];
