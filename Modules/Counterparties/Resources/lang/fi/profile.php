<?php

declare(strict_types=1);

return [
    'page_title' => 'Vastapuoli',
    'fallback_account' => 'Tili',
    'fallback_counterparty' => 'Vastapuoli',

    'edit_display_name' => 'Muokkaa näyttönimeä',

    'hero_net_received' => 'Netto saatu',
    'hero_12mo_total' => '12 kuukauden summa',
    'hero_transactions' => 'Tapahtumat',
    'hero_first_seen' => 'Ensimmäinen havainto',

    'tabs' => [
        'overview' => 'Yleiskatsaus',
        'transactions' => 'Tapahtumat',
        'chains' => 'Ketjut',
        'aliases' => 'Aliakset',
        'transfers' => 'Siirrot',
        'entries' => 'Kirjaukset',
        'payments' => 'Maksut',
        'tax_years' => 'Verovuodet',
    ],

    'tablist_aria' => 'Vastapuolen osiot',

    'tab_note_personal' => '— henkilöyhteystiedoille ei muodostu rahoitusketjuja',
    'tab_note_bank' => '— pankkikuluvastapuoli ei muodosta rahoitusketjuja',
    'tab_note_bank_institution' => '— rahoituslaitosvastapuolille ei muodostu rahoitusketjuja',
    'tab_note_government' => '— julkishallinnon vastapuolille ei muodostu rahoitusketjuja',

    'recent_activity' => 'Viimeaikainen toiminta',
    'recurring' => 'Toistuva',
    'uncategorized' => 'Luokittelematon',
    'no_recent_transactions' => 'Tälle vastapuolelle ei ole vielä kirjattu tapahtumia.',
    'see_all' => 'Näytä kaikki :count →',

    'bank' => [
        'fees_heading' => 'Pankkikulut kategorioittain',
        'activity_heading' => 'Toiminta kategorioittain',
        'no_fees' => 'Tälle vastapuolelle ei ole vielä kirjattu kuluja.',
    ],

    'government' => [
        'intro' => 'Vuosittainen erittely kaikilta vuosilta, joilla on toimintaa. Kuluva vuosi on korostettu.',
        'no_payments' => 'Tälle vastapuolelle ei ole vielä kirjattu maksuja.',
    ],

    'merchant' => [
        'categories' => 'Kategoriat',

        'categories_empty_html' => 'Ei vielä kategorioita — luokittelemattomat tapahtumat näkyvät osiossa <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Luokittelu</a>.',
        'no_recurring' => 'Toistuvia kaavoja ei havaittu.',
        'per_month_suffix' => '/kk',
        'funding_chain' => 'Rahoitusketju',
        'no_funding_chain' => 'Rahoitusketjua ei ole vielä havaittu. Rahoitusketjun ratkaisu edellyttää sekä ASN- että PayPal-tietojen tuontia.',
        'open_chains' => 'Avaa ketjujen tarkistus →',
    ],

    'personal' => [
        'contact' => 'Yhteystieto',
        'add_tag' => '+ Lisää tunniste',
        'no_recurring' => 'Toistuvuutta ei havaittu — henkilösiirrot noudattavat harvoin tarkkaa maksuväliä; säännöllisetkin vuokranjaot voivat siirtyä päivällä.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Tätä vastapuolta ei ole vielä merkitty',
        'not_labelled_body' => 'Tuntemattomien merkitseminen auttaa yleisnäkymää näyttämään tarkat kuukausisummat ja rahoitusketjut.',
        'label_cta' => 'Merkitse tämä vastapuoli',
    ],

    'support' => [
        'contact_help' => 'Yhteystiedot ja ohje',
        'sign_in_apply' => 'Kirjaudu · hae',
        'your_rights' => 'Oikeutesi · vastusta',
        'cancel' => 'Irtisano',
        'help_support' => 'Ohje ja tuki',
        'cheaper_plan' => 'Edullisempi paketti',
        'aria_gov' => 'Avun saaminen',
        'aria_merchant' => 'Tuki ja irtisanominen',
        'heading_gov' => 'Avun saaminen',
        'heading_merchant' => 'Tuki ja irtisanominen',
        'cancel_by_email' => 'Irtisano sähköpostitse',
    ],
];
