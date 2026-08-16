<?php

declare(strict_types=1);

return [
    'page_title' => 'Kita šalis',
    'fallback_account' => 'Sąskaita',
    'fallback_counterparty' => 'Kita šalis',

    'edit_display_name' => 'Redaguoti rodomą pavadinimą',

    'hero_net_received' => 'Grynosios gautos lėšos',
    'hero_12mo_total' => '12 mėnesių suma',
    'hero_transactions' => 'Operacijos',
    'hero_first_seen' => 'Pirmą kartą matyta',

    'tabs' => [
        'overview' => 'Apžvalga',
        'transactions' => 'Operacijos',
        'chains' => 'Grandinės',
        'aliases' => 'Alternatyvūs pavadinimai',
        'transfers' => 'Pavedimai',
        'entries' => 'Įrašai',
        'payments' => 'Mokėjimai',
        'tax_years' => 'Mokestiniai metai',
    ],

    'tab_note_personal' => '— asmeniniams kontaktams finansavimo grandinių nėra',
    'tab_note_bank' => '— banko mokesčių kita šalis finansavimo grandinių nesukuria',
    'tab_note_government' => '— valstybės institucijoms finansavimo grandinių nėra',

    'recent_activity' => 'Naujausia veikla',
    'recurring' => 'Pasikartojantys',
    'uncategorized' => 'Be kategorijos',
    'no_recent_transactions' => 'Šiai kitai šaliai operacijų dar neužfiksuota.',
    'see_all' => 'Žiūrėti visas (:count) →',

    'bank' => [
        'fees_heading' => 'Banko mokesčiai pagal kategoriją',
        'no_fees' => 'Šiai kitai šaliai mokesčių dar neužfiksuota.',
    ],

    'government' => [
        'intro' => 'Metinis išskaidymas per visus metus, kuriais buvo veiklos. Einamieji metai išryškinti.',
        'no_payments' => 'Šiai kitai šaliai mokėjimų dar neužfiksuota.',
    ],

    'merchant' => [
        'categories' => 'Kategorijos',

        'categories_empty_html' => 'Kol kas kategorijų nėra — operacijos be kategorijos rodomos skiltyje <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorijų priskyrimas</a>.',
        'no_recurring' => 'Pasikartojančių modelių neaptikta.',
        'per_month_suffix' => '/mėn.',
        'funding_chain' => 'Finansavimo grandinė',
        'no_funding_chain' => 'Finansavimo grandinės dar neaptikta. Finansavimo grandinėms nustatyti reikia importuotų ASN ir PayPal duomenų.',
        'open_chains' => 'Atverti grandinių peržiūrą →',
    ],

    'personal' => [
        'contact' => 'Kontaktas',
        'add_tag' => '+ Pridėti žymą',
        'no_recurring' => 'Pasikartojimų neaptikta — asmeniniai pavedimai retai vyksta griežtu dažnumu; net reguliariai dalijamas nuomos mokestis gali keisti datas.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ši kita šalis dar nepažymėta',
        'not_labelled_body' => 'Nežinomų šalių pažymėjimas padeda apžvalgoje rodyti tikslias mėnesio sumas ir finansavimo grandines.',
        'label_cta' => 'Pažymėti šią kitą šalį',
    ],

    'support' => [
        'contact_help' => 'Kontaktai ir pagalba',
        'sign_in_apply' => 'Prisijungti · teikti prašymą',
        'your_rights' => 'Tavo teisės · nesutikti',
        'cancel' => 'Nutraukti',
        'help_support' => 'Pagalba ir palaikymas',
        'cheaper_plan' => 'Pigesnis planas',
        'aria_gov' => 'Kaip gauti pagalbos',
        'aria_merchant' => 'Palaikymas ir nutraukimas',
        'heading_gov' => 'Kaip gauti pagalbos',
        'heading_merchant' => 'Palaikymas ir nutraukimas',
        'cancel_by_email' => 'Nutraukti el. paštu',
    ],
];
