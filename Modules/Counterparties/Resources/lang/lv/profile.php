<?php

declare(strict_types=1);

return [
    'page_title' => 'Darījuma partneris',
    'fallback_account' => 'Konts',
    'fallback_counterparty' => 'Darījuma partneris',

    'edit_display_name' => 'Rediģēt attēloto nosaukumu',

    'hero_net_received' => 'Neto saņemts',
    'hero_12mo_total' => '12 mēnešu kopsumma',
    'hero_transactions' => 'Darījumi',
    'hero_first_seen' => 'Pirmoreiz redzēts',

    'tabs' => [
        'overview' => 'Pārskats',
        'transactions' => 'Darījumi',
        'chains' => 'Ķēdes',
        'aliases' => 'Aizstājvārdi',
        'transfers' => 'Pārskaitījumi',
        'entries' => 'Ieraksti',
        'payments' => 'Maksājumi',
        'tax_years' => 'Taksācijas gadi',
    ],

    'tablist_aria' => 'Darījuma partnera sadaļas',

    'tab_note_personal' => '— privātiem kontaktiem finansējuma ķēžu nav',
    'tab_note_bank' => '— bankas komisiju partneris finansējuma ķēdes neveido',
    'tab_note_government' => '— valsts iestāžu partneriem finansējuma ķēžu nav',

    'recent_activity' => 'Nesenā aktivitāte',
    'recurring' => 'Regulārie maksājumi',
    'uncategorized' => 'Bez kategorijas',
    'no_recent_transactions' => 'Šim darījuma partnerim vēl nav reģistrēts neviens darījums.',
    'see_all' => 'Skatīt visus :count →',

    'bank' => [
        'fees_heading' => 'Bankas komisijas pa kategorijām',
        'no_fees' => 'Šim darījuma partnerim vēl nav reģistrēta neviena komisija.',
    ],

    'government' => [
        'intro' => 'Sadalījums pa gadiem visos gados ar aktivitāti. Pašreizējais gads ir izcelts.',
        'no_payments' => 'Šim darījuma partnerim vēl nav reģistrēts neviens maksājums.',
    ],

    'merchant' => [
        'categories' => 'Kategorijas',

        'categories_empty_html' => 'Vēl nav kategoriju — darījumi bez kategorijas ir redzami sadaļā <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizēšana</a>.',
        'no_recurring' => 'Regulāri modeļi nav atklāti.',
        'per_month_suffix' => '/mēn.',
        'funding_chain' => 'Finansējuma ķēde',
        'no_funding_chain' => 'Finansējuma ķēde vēl nav atklāta. Finansējuma ķēžu atrisināšanai ir nepieciešami ASN un PayPal datu importi.',
        'open_chains' => 'Atvērt ķēžu pārskatīšanu →',
    ],

    'personal' => [
        'contact' => 'Kontakts',
        'add_tag' => '+ Pievienot atzīmi',
        'no_recurring' => 'Regulāri maksājumi nav atklāti — privātie pārskaitījumi reti seko stingram biežumam; pat regulāri īres maksājumi var mainīt datumus.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Šis darījuma partneris vēl nav marķēts',
        'not_labelled_body' => 'Nezināmo partneru marķēšana palīdz pārskatā rādīt precīzas mēneša kopsummas un finansējuma ķēdes.',
        'label_cta' => 'Marķēt šo darījuma partneri',
    ],

    'support' => [
        'contact_help' => 'Kontakti un palīdzība',
        'sign_in_apply' => 'Pieteikties · iesniegt',
        'your_rights' => 'Jūsu tiesības · iebilst',
        'cancel' => 'Atteikties',
        'help_support' => 'Palīdzība un atbalsts',
        'cheaper_plan' => 'Lētāks plāns',
        'aria_gov' => 'Kā saņemt palīdzību',
        'aria_merchant' => 'Atbalsts un atteikšanās',
        'heading_gov' => 'Kā saņemt palīdzību',
        'heading_merchant' => 'Atbalsts un atteikšanās',
        'cancel_by_email' => 'Atteikties pa e-pastu',
    ],
];
