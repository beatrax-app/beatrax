<?php

declare(strict_types=1);

return [
    'page_title' => 'Controparte',
    'fallback_account' => 'Conto',
    'fallback_counterparty' => 'Controparte',

    'edit_display_name' => 'Modifica il nome visualizzato',

    'hero_net_received' => 'Netto ricevuto',
    'hero_12mo_total' => 'Totale 12 mesi',
    'hero_transactions' => 'Transazioni',
    'hero_first_seen' => 'Prima volta',

    'tabs' => [
        'overview' => 'Panoramica',
        'transactions' => 'Transazioni',
        'chains' => 'Catene',
        'aliases' => 'Alias',
        'transfers' => 'Trasferimenti',
        'entries' => 'Voci',
        'payments' => 'Pagamenti',
        'tax_years' => 'Anni fiscali',
    ],

    'tablist_aria' => 'Sezioni della controparte',

    'tab_note_personal' => '— nessuna catena di finanziamento per i contatti personali',
    'tab_note_bank' => '— una controparte di commissioni bancarie non genera catene di finanziamento',
    'tab_note_government' => '— nessuna catena di finanziamento per le controparti pubbliche',

    'recent_activity' => 'Attività recente',
    'recurring' => 'Ricorrente',
    'uncategorized' => 'Senza categoria',
    'no_recent_transactions' => 'Ancora nessuna transazione registrata per questa controparte.',
    'see_all' => 'Vedi tutte le :count →',

    'bank' => [
        'fees_heading' => 'Commissioni bancarie per categoria',
        'no_fees' => 'Ancora nessuna commissione registrata su questa controparte.',
    ],

    'government' => [
        'intro' => "Ripartizione annuale su tutti gli anni con attività. L'anno in corso è evidenziato.",
        'no_payments' => 'Ancora nessun pagamento registrato per questa controparte.',
    ],

    'merchant' => [
        'categories' => 'Categorie',

        'categories_empty_html' => 'Ancora nessuna categoria — le transazioni senza categoria compaiono in <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorizzazione</a>.',
        'no_recurring' => 'Nessuno schema ricorrente rilevato.',
        'per_month_suffix' => '/mese',
        'funding_chain' => 'Catena di finanziamento',
        'no_funding_chain' => 'Nessuna catena di finanziamento rilevata finora. Per risolvere le catene di finanziamento servono importazioni di dati ASN + PayPal.',
        'open_chains' => 'Apri la revisione delle catene →',
    ],

    'personal' => [
        'contact' => 'Contatto',
        'add_tag' => '+ Aggiungi etichetta',
        'no_recurring' => 'Nessuna ricorrenza rilevata — i trasferimenti personali seguono raramente una cadenza rigida; anche un affitto diviso regolarmente può cambiare data.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Questa controparte non è ancora etichettata',
        'not_labelled_body' => 'Etichettare le sconosciute aiuta la dashboard a mostrare totali mensili e catene di finanziamento accurati.',
        'label_cta' => 'Etichetta questa controparte',
    ],

    'support' => [
        'contact_help' => 'Contatti e aiuto',
        'sign_in_apply' => 'Accedi · richiedi',
        'your_rights' => 'I tuoi diritti · opponiti',
        'cancel' => 'Disdici',
        'help_support' => 'Aiuto e assistenza',
        'cheaper_plan' => 'Piano più economico',
        'aria_gov' => 'Come ottenere aiuto',
        'aria_merchant' => 'Assistenza e disdetta',
        'heading_gov' => 'Come ottenere aiuto',
        'heading_merchant' => 'Assistenza e disdetta',
        'cancel_by_email' => 'Disdici via email',
    ],
];
