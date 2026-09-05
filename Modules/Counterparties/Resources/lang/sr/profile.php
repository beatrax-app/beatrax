<?php

declare(strict_types=1);

return [
    'page_title' => 'Druga strana',
    'fallback_account' => 'Račun',
    'fallback_counterparty' => 'Druga strana',

    'edit_display_name' => 'Izmeni prikazano ime',

    'hero_net_received' => 'Neto primljeno',
    'hero_12mo_total' => 'Ukupno u 12 meseci',
    'hero_transactions' => 'Transakcije',
    'hero_first_seen' => 'Prvi put viđeno',

    'tabs' => [
        'overview' => 'Pregled',
        'transactions' => 'Transakcije',
        'chains' => 'Lanci',
        'aliases' => 'Aliasi',
        'transfers' => 'Prenosi',
        'entries' => 'Stavke',
        'payments' => 'Plaćanja',
        'tax_years' => 'Poreske godine',
    ],

    'tablist_aria' => 'Odeljci druge strane',

    'tab_note_personal' => '— nema lanaca finansiranja za lične kontakte',
    'tab_note_bank' => '— druga strana za bankarske naknade ne stvara lance finansiranja',
    'tab_note_bank_institution' => '— nema lanaca finansiranja za institucionalne druge strane',
    'tab_note_government' => '— nema lanaca finansiranja za državne ustanove',

    'recent_activity' => 'Nedavna aktivnost',
    'recurring' => 'Ponavljajuće',
    'uncategorized' => 'Bez kategorije',
    'no_recent_transactions' => 'Za ovu drugu stranu još nema zabeleženih transakcija.',
    'see_all' => 'Prikaži sve (:count) →',

    'bank' => [
        'fees_heading' => 'Bankarske naknade po kategoriji',
        'activity_heading' => 'Aktivnost po kategoriji',
        'no_fees' => 'Za ovu drugu stranu još nema zabeleženih naknada.',
    ],

    'government' => [
        'intro' => 'Godišnji pregled za sve godine sa aktivnošću. Tekuća godina je istaknuta.',
        'no_payments' => 'Za ovu drugu stranu još nema zabeleženih plaćanja.',
    ],

    'merchant' => [
        'categories' => 'Kategorije',

        'categories_empty_html' => 'Još nema kategorija — transakcije bez kategorije prikazuju se u odeljku <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorizacija</a>.',
        'no_recurring' => 'Nisu otkriveni ponavljajući obrasci.',
        'per_month_suffix' => '/mes.',
        'funding_chain' => 'Lanac finansiranja',
        'no_funding_chain' => 'Lanac finansiranja još nije otkriven. Za razrešavanje lanca finansiranja potreban je uvoz podataka iz ASN + PayPal.',
        'open_chains' => 'Otvori pregled lanaca →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Dodaj oznaku',
        'no_recurring' => 'Ponavljanje nije otkriveno — lični prenosi retko prate strogu učestalost; čak i redovne podele kirije mogu da menjaju datume.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ova druga strana još nije označena',
        'not_labelled_body' => 'Označavanje nepoznatih pomaže kontrolnoj tabli da prikaže tačne mesečne ukupne iznose i lance finansiranja.',
        'label_cta' => 'Označi ovu drugu stranu',
    ],

    'support' => [
        'contact_help' => 'Kontakt i pomoć',
        'sign_in_apply' => 'Prijavi se · podnesi zahtev',
        'your_rights' => 'Tvoja prava · uloži prigovor',
        'cancel' => 'Otkaži pretplatu',
        'help_support' => 'Pomoć i podrška',
        'cheaper_plan' => 'Jeftiniji paket',
        'aria_gov' => 'Traženje pomoći',
        'aria_merchant' => 'Podrška i otkazivanje',
        'heading_gov' => 'Traženje pomoći',
        'heading_merchant' => 'Podrška i otkazivanje',
        'cancel_by_email' => 'Otkaži e-poštom',
        'withheld' => 'veza zadržana',
        'notes_language' => 'Na jeziku :language, kako to objavljuje pružalac.',
    ],
];
