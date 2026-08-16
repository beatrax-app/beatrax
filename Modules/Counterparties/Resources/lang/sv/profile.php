<?php

declare(strict_types=1);

return [
    'page_title' => 'Motpart',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Motpart',

    'edit_display_name' => 'Redigera visningsnamn',

    'hero_net_received' => 'Netto mottaget',
    'hero_12mo_total' => 'Totalt 12 månader',
    'hero_transactions' => 'Transaktioner',
    'hero_first_seen' => 'Först sedd',

    'tabs' => [
        'overview' => 'Översikt',
        'transactions' => 'Transaktioner',
        'chains' => 'Kedjor',
        'aliases' => 'Alias',
        'transfers' => 'Överföringar',
        'entries' => 'Poster',
        'payments' => 'Betalningar',
        'tax_years' => 'Beskattningsår',
    ],

    'tab_note_personal' => '— inga finansieringskedjor för privata kontakter',
    'tab_note_bank' => '— en motpart för bankavgifter genererar inga finansieringskedjor',
    'tab_note_government' => '— inga finansieringskedjor för myndighetsmotparter',

    'recent_activity' => 'Senaste aktivitet',
    'recurring' => 'Återkommande',
    'uncategorized' => 'Okategoriserat',
    'no_recent_transactions' => 'Inga transaktioner registrerade för den här motparten ännu.',
    'see_all' => 'Visa alla :count →',

    'bank' => [
        'fees_heading' => 'Bankavgifter per kategori',
        'no_fees' => 'Inga avgifter registrerade på den här motparten ännu.',
    ],

    'government' => [
        'intro' => 'Årlig fördelning över alla år med aktivitet. Innevarande år är framhävt.',
        'no_payments' => 'Inga betalningar registrerade för den här motparten ännu.',
    ],

    'merchant' => [
        'categories' => 'Kategorier',

        'categories_empty_html' => 'Inga kategorier ännu — okategoriserade transaktioner visas i <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorisering</a>.',
        'no_recurring' => 'Inga återkommande mönster upptäckta.',
        'per_month_suffix' => '/mån',
        'funding_chain' => 'Finansieringskedja',
        'no_funding_chain' => 'Ingen finansieringskedja har upptäckts ännu. Import av data från ASN + PayPal krävs för att lösa finansieringskedjor.',
        'open_chains' => 'Öppna granskning av kedjor →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Lägg till tagg',
        'no_recurring' => 'Inget återkommande mönster upptäckt — privata överföringar följer sällan ett strikt intervall; även regelbundna hyresdelningar kan flytta datum.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Den här motparten är inte märkt ännu',
        'not_labelled_body' => 'Att märka okända motparter hjälper översikten att visa korrekta månadssummor och finansieringskedjor.',
        'label_cta' => 'Märk den här motparten',
    ],

    'support' => [
        'contact_help' => 'Kontakt & hjälp',
        'sign_in_apply' => 'Logga in · ansök',
        'your_rights' => 'Dina rättigheter · invänd',
        'cancel' => 'Säg upp',
        'help_support' => 'Hjälp & support',
        'cheaper_plan' => 'Billigare abonnemang',
        'aria_gov' => 'Få hjälp',
        'aria_merchant' => 'Support och uppsägning',
        'heading_gov' => 'Få hjälp',
        'heading_merchant' => 'Support & uppsägning',
        'cancel_by_email' => 'Säg upp via e-post',
    ],
];
