<?php

declare(strict_types=1);

return [
    'page_title' => 'Vastapuolten käsittely',
    'heading' => 'Käsittele tuntemattomat vastapuolet',

    'progress' => ':seen / :total · :percent % · ~:minutes min jäljellä',
    'progress_aria' => 'Käsittelyn edistyminen',

    'all_caught_aria' => 'Kaikki vastapuolet merkitty',
    'all_caught_heading' => '🎉 Kaikki käsitelty — jokainen vastapuoli on merkitty.',
    'back_to_index' => 'Takaisin vastapuoliin →',

    'meta' => ':count tapahtuma · viimeksi nähty :date|:count tapahtumaa · viimeksi nähty :date',

    'suggested_aria' => 'Ehdotettu osuma',
    'suggestion_medium' => '✨ Ehkä **:name** — varmuus keskitasoa',
    'suggestion_low' => 'Kuvio-osuma: **:name** — varmuus matala. Tarkista ennen linkitystä.',
    'suggestion_high' => '✨ Näyttää olevan **:name** — varmuus korkea',

    'reasoning' => ':hits/:total viimeaikaisesta tapahtumasta tällä IBANilla viittaa :name.|:hits/:total viimeaikaisesta tapahtumasta tällä IBANilla viittaa :name.',
    'yes_link' => 'Kyllä, linkitä kohteeseen :name ↵',
    'no_not' => 'Ei, ei ole :name',

    'recent_on_iban' => 'Tämän IBANin viimeisimmät tapahtumat',
    'recent_on_counterparty' => 'Viimeisimmät tapahtumat tämän vastapuolen kanssa',
    'no_transactions_yet' => 'Tapahtumia ei ole vielä kirjattu.',

    'label_manually' => 'Tai merkitse käsin',
    'label_question' => 'Mikä tämä vastapuoli on?',
    'display_name_label' => 'Näyttönimi',
    'type_label' => 'Tyyppi',
    'type_merchant' => 'Kauppias',
    'type_personal' => 'Henkilö',
    'type_bank' => 'Pankki',
    'type_government' => 'Julkishallinto',
    'save_label' => 'Tallenna merkintä',
    'name_required' => 'Anna tälle vastapuolelle ensin nimi.',
    'draft_kept' => 'Kirjoittamasi säilyy, kun siirryt jonossa eteenpäin.',

    'skip' => 'Ohita toistaiseksi',
    'mark_ignored' => 'Älä kysy tästä enää',
    'skip_note' => 'Ohittaminen ei kirjoita mitään — se vain siirtyy seuraavaan tuntemattomaan.',
    // i18n-review: fi · mark_ignored_note — "huomiotta jätetty" is the ignore state,
    // chosen because "ohitettu" is the word the Skip button above it already uses and
    // these two lines exist to tell the two apart. A native should pick between them.
    'mark_ignored_note' => 'Tämä merkitsee vastapuolen huomiotta jätetyksi, jolloin se pysyy poissa tästä jonosta. Sen nimi, tyyppi ja historia säilyvät ennallaan, ja voit merkitä sen myöhemmin Vastapuolet-sivulla.',
    'previous' => 'Edellinen tuntematon',

    'kbd_yes' => 'kyllä',
    'kbd_no' => 'ei',
    'kbd_skip' => 'ohita',
    'kbd_next' => 'seuraava',

    'footer' => ':seen jo merkitty · :count jäljellä',
];
