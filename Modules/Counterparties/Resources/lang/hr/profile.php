<?php

declare(strict_types=1);

return [
    'page_title' => 'Protustranka',
    'fallback_account' => 'Račun',
    'fallback_counterparty' => 'Protustranka',

    'edit_display_name' => 'Uredi prikazano ime',

    'hero_net_received' => 'Neto primljeno',
    'hero_12mo_total' => 'Ukupno u 12 mjeseci',
    'hero_transactions' => 'Transakcije',
    'hero_first_seen' => 'Prvi put viđeno',

    'tabs' => [
        'overview' => 'Pregled',
        'transactions' => 'Transakcije',
        'chains' => 'Lanci',
        'aliases' => 'Aliasi',
        'transfers' => 'Prijenosi',
        'entries' => 'Stavke',
        'payments' => 'Plaćanja',
        'tax_years' => 'Porezne godine',
    ],

    'tablist_aria' => 'Odjeljci protustranke',

    'tab_note_personal' => '— nema lanaca financiranja za osobne kontakte',
    'tab_note_bank' => '— protustranka za bankovne naknade ne stvara lance financiranja',
    'tab_note_bank_institution' => '— nema lanaca financiranja za institucionalne protustranke',
    'tab_note_government' => '— nema lanaca financiranja za državne ustanove',

    'recent_activity' => 'Nedavna aktivnost',
    'recurring' => 'Ponavljajuće',
    'uncategorized' => 'Bez kategorije',
    'no_recent_transactions' => 'Za ovu protustranku još nema zabilježenih transakcija.',
    'see_all' => 'Prikaži sve (:count) →',

    'bank' => [
        'fees_heading' => 'Bankovne naknade po kategoriji',
        'activity_heading' => 'Aktivnost po kategoriji',
        'no_fees' => 'Za ovu protustranku još nema zabilježenih naknada.',
    ],

    'government' => [
        'intro' => 'Godišnja raščlamba za sve godine s aktivnošću. Tekuća godina je istaknuta.',
        'no_payments' => 'Za ovu protustranku još nema zabilježenih plaćanja.',
    ],

    'merchant' => [
        'categories' => 'Kategorije',

        'categories_empty_html' => 'Još nema kategorija — transakcije bez kategorije prikazuju se u odjeljku <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizacija</a>.',
        'no_recurring' => 'Nisu otkriveni ponavljajući obrasci.',
        'per_month_suffix' => '/mj.',
        'funding_chain' => 'Lanac financiranja',
        'no_funding_chain' => 'Lanac financiranja još nije otkriven. Za razrješavanje lanca financiranja potreban je uvoz podataka iz ASN + PayPal.',
        'open_chains' => 'Otvori pregled lanaca →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Dodaj oznaku',
        'no_recurring' => 'Ponavljanje nije otkriveno — osobni prijenosi rijetko slijede strogu učestalost; čak i redovite podjele najamnine mogu mijenjati datume.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ova protustranka još nije označena',
        'not_labelled_body' => 'Označavanje nepoznatih pomaže nadzornoj ploči prikazati točne mjesečne ukupne iznose i lance financiranja.',
        'label_cta' => 'Označi ovu protustranku',
    ],

    'support' => [
        'contact_help' => 'Kontakt i pomoć',
        'sign_in_apply' => 'Prijavi se · podnesi zahtjev',
        'your_rights' => 'Tvoja prava · uloži prigovor',
        'cancel' => 'Otkaži pretplatu',
        'help_support' => 'Pomoć i podrška',
        'cheaper_plan' => 'Jeftiniji paket',
        'aria_gov' => 'Traženje pomoći',
        'aria_merchant' => 'Podrška i otkazivanje',
        'heading_gov' => 'Traženje pomoći',
        'heading_merchant' => 'Podrška i otkazivanje',
        'cancel_by_email' => 'Otkaži e-poštom',
        'withheld' => 'poveznica zadržana',
        'notes_language' => 'Na jeziku :language, kako to objavljuje pružatelj.',
    ],
];
