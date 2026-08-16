<?php

declare(strict_types=1);

return [
    'page_title' => 'Nasprotna stranka',
    'fallback_account' => 'Račun',
    'fallback_counterparty' => 'Nasprotna stranka',

    'edit_display_name' => 'Uredi prikazano ime',

    'hero_net_received' => 'Neto prejeto',
    'hero_12mo_total' => 'Skupaj v 12 mesecih',
    'hero_transactions' => 'Transakcije',
    'hero_first_seen' => 'Prvič opaženo',

    'tabs' => [
        'overview' => 'Pregled',
        'transactions' => 'Transakcije',
        'chains' => 'Verige',
        'aliases' => 'Aliasi',
        'transfers' => 'Prenosi',
        'entries' => 'Postavke',
        'payments' => 'Plačila',
        'tax_years' => 'Davčna leta',
    ],

    'tab_note_personal' => '— za osebne stike ni verig financiranja',
    'tab_note_bank' => '— nasprotna stranka za bančne provizije ne ustvarja verig financiranja',
    'tab_note_government' => '— za državne ustanove ni verig financiranja',

    'recent_activity' => 'Nedavna dejavnost',
    'recurring' => 'Ponavljajoče',
    'uncategorized' => 'Brez kategorije',
    'no_recent_transactions' => 'Za to nasprotno stranko še ni zabeleženih transakcij.',
    'see_all' => 'Prikaži vse (:count) →',

    'bank' => [
        'fees_heading' => 'Bančne provizije po kategoriji',
        'no_fees' => 'Za to nasprotno stranko še ni zabeleženih provizij.',
    ],

    'government' => [
        'intro' => 'Letna razčlenitev za vsa leta z dejavnostjo. Tekoče leto je poudarjeno.',
        'no_payments' => 'Za to nasprotno stranko še ni zabeleženih plačil.',
    ],

    'merchant' => [
        'categories' => 'Kategorije',

        'categories_empty_html' => 'Kategorij še ni — transakcije brez kategorije se prikažejo v razdelku <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizacija</a>.',
        'no_recurring' => 'Ponavljajočih vzorcev ni zaznanih.',
        'per_month_suffix' => '/mes.',
        'funding_chain' => 'Veriga financiranja',
        'no_funding_chain' => 'Veriga financiranja še ni zaznana. Za razrešitev verige financiranja je potreben uvoz podatkov iz ASN + PayPal.',
        'open_chains' => 'Odpri pregled verig →',
    ],

    'personal' => [
        'contact' => 'Stik',
        'add_tag' => '+ Dodaj oznako',
        'no_recurring' => 'Ponavljanja ni zaznanega — osebni prenosi redko sledijo strogi pogostosti; tudi redne razdelitve najemnine lahko premikajo datume.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ta nasprotna stranka še ni označena',
        'not_labelled_body' => 'Označevanje neznanih pomaga nadzorni plošči prikazati točne mesečne skupne zneske in verige financiranja.',
        'label_cta' => 'Označi to nasprotno stranko',
    ],

    'support' => [
        'contact_help' => 'Stik in pomoč',
        'sign_in_apply' => 'Prijavi se · oddaj vlogo',
        'your_rights' => 'Tvoje pravice · vloži ugovor',
        'cancel' => 'Odpovej naročnino',
        'help_support' => 'Pomoč in podpora',
        'cheaper_plan' => 'Cenejši paket',
        'aria_gov' => 'Iskanje pomoči',
        'aria_merchant' => 'Podpora in odpoved',
        'heading_gov' => 'Iskanje pomoči',
        'heading_merchant' => 'Podpora in odpoved',
        'cancel_by_email' => 'Odpovej po e-pošti',
    ],
];
