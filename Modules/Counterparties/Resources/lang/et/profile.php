<?php

declare(strict_types=1);

return [
    'page_title' => 'Vastaspool',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Vastaspool',

    'edit_display_name' => 'Muuda kuvatavat nime',

    'hero_net_received' => 'Neto laekunud',
    'hero_12mo_total' => '12 kuu kogusumma',
    'hero_transactions' => 'Tehingud',
    'hero_first_seen' => 'Esmakordselt nähtud',

    'tabs' => [
        'overview' => 'Ülevaade',
        'transactions' => 'Tehingud',
        'chains' => 'Ahelad',
        'aliases' => 'Aliased',
        'transfers' => 'Ülekanded',
        'entries' => 'Kirjed',
        'payments' => 'Maksed',
        'tax_years' => 'Maksuaastad',
    ],

    'tablist_aria' => 'Vastaspoole jaotised',

    'tab_note_personal' => '— eraisikutest kontaktidel rahastusahelaid ei ole',
    'tab_note_bank' => '— pangatasude vastaspool ei tekita rahastusahelaid',
    'tab_note_government' => '— riigiasutustest vastaspooltel rahastusahelaid ei ole',

    'recent_activity' => 'Hiljutine tegevus',
    'recurring' => 'Korduv',
    'uncategorized' => 'Kategoriseerimata',
    'no_recent_transactions' => 'Selle vastaspoole kohta pole veel ühtegi tehingut.',
    'see_all' => 'Vaata kõiki :count →',

    'bank' => [
        'fees_heading' => 'Pangatasud kategooriate kaupa',
        'no_fees' => 'Selle vastaspoole kohta pole veel tasusid kirjas.',
    ],

    'government' => [
        'intro' => 'Aastane jaotus kõigi tegevusega aastate lõikes. Käesolev aasta on esile tõstetud.',
        'no_payments' => 'Selle vastaspoole kohta pole veel makseid kirjas.',
    ],

    'merchant' => [
        'categories' => 'Kategooriad',

        'categories_empty_html' => 'Kategooriaid veel pole — kategoriseerimata tehingud leiad jaotisest <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategoriseerimine</a>.',
        'no_recurring' => 'Korduvaid mustreid ei tuvastatud.',
        'per_month_suffix' => '/kuus',
        'funding_chain' => 'Rahastusahel',
        'no_funding_chain' => 'Rahastusahelat pole veel tuvastatud. Rahastusahela lahendamiseks on vaja importida ASN-i ja PayPali andmed.',
        'open_chains' => 'Ava ahelate ülevaatus →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Lisa silt',
        'no_recurring' => 'Korduvust ei tuvastatud — eraisikute ülekanded järgivad harva ranget sagedust; isegi regulaarne üüri jagamine võib kuupäevades nihkuda.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Sellel vastaspoolel pole veel silti',
        'not_labelled_body' => 'Tundmatute sildistamine aitab ülevaates näidata täpseid kuusummasid ja rahastusahelaid.',
        'label_cta' => 'Määra sellele vastaspoolele silt',
    ],

    'support' => [
        'contact_help' => 'Kontakt ja abi',
        'sign_in_apply' => 'Logi sisse · esita taotlus',
        'your_rights' => 'Sinu õigused · esita vastuväide',
        'cancel' => 'Ütle leping üles',
        'help_support' => 'Abi ja tugi',
        'cheaper_plan' => 'Odavam pakett',
        'aria_gov' => 'Abi saamine',
        'aria_merchant' => 'Tugi ja lepingu ülesütlemine',
        'heading_gov' => 'Abi saamine',
        'heading_merchant' => 'Tugi ja lepingu ülesütlemine',
        'cancel_by_email' => 'Ütle leping üles e-postiga',
    ],
];
