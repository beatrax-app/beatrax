<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliakset',
    'heading' => 'Aliakset',
    'subtitle' => 'Selkeitä nimiä, jotka olet opettanut Beatraxille tiliotteidesi kryptisille kuvauksille. Muokkaa rivin yleistettyä mallia laajentaaksesi tai kaventaaksesi sitä, mitkä muut tapahtumat perivät saman selkeän nimen.',
    'dismiss' => 'ohita',

    'selected_count' => ':count valittu',
    'merge_selected' => 'Yhdistä valitut',

    'empty_heading' => 'Ei vielä aliaksia',
    'empty_body' => 'Aliakset ilmestyvät tähän, kun napsautat tuonnin esikatselurivillä kursivoitua raakakuvausta ja annat sille selkeän nimen.',

    'col_select' => 'Valitse',
    'col_raw' => 'Raakakuvaus',
    'col_generalized' => 'Yleistetty malli',
    'col_friendly' => 'Selkeä nimi',
    'col_actions' => 'Toiminnot',

    'select_alias_aria' => 'Valitse alias :name',
    'generalized_pattern_aria' => 'Yleistetty malli',

    'save' => 'Tallenna',
    'cancel' => 'Peruuta',
    'edit' => 'Muokkaa',
    'delete' => 'Poista',
    'delete_confirm' => "Poistetaanko tämä alias? Tulevissa tuonneissa ':pattern' palaa raakakuvaukseksi.",

    'backup_transfer' => 'Varmuuskopiointi ja siirto',
    'export_yaml' => 'Vie aliakset YAML-muodossa',

    'export_help_html' => 'Lataa tiedoston <code class="font-mono">aliases.yaml</code> yhteisökorpuksen muodossa.',
    'import_from_yaml' => 'Tuo YAML-tiedostosta',
    'parse_preview' => 'Jäsennä ja esikatsele',
    'cancel_import' => 'Peruuta tuonti',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    'diff_new' => ':count uusi|:count uutta',
    'diff_unchanged' => ':count ennallaan|:count ennallaan',
    'diff_conflicts' => ':count ristiriita|:count ristiriitaa',

    'conflicts_heading' => 'Ristiriidat',
    'conflict_name' => 'nimi — nykyinen: :existing → tiedosto: :file',
    'conflict_pattern_existing' => 'malli — nykyinen:',
    'conflict_file' => '→ tiedosto:',
    'resolution_for_aria' => 'Ratkaisu kohteelle :pattern',
    'keep_yours' => 'Säilytä omasi',
    'replace' => 'Korvaa',
    'confirm_import' => 'Vahvista tuonti',

    'preview_aria' => 'Esikatsele omia tapahtumia vasten',
    'test_heading' => 'Testaa omilla tapahtumillani',
    'test_help' => 'Muokkaa rivin yleistettyä mallia, niin näet mitkä tapahtumat siihen täsmäisivät.',
    'typing' => 'Kirjoitetaan…',
    'matches' => 'Täsmää :count tapahtumaan viimeaikaisessa historiassasi.|Täsmää :count tapahtumaan viimeaikaisessa historiassasi.',

    'merge_modal_title' => 'Yhdistä :count alias|Yhdistä :count aliasta',

    'merge_modal_help_html' => 'Jäljelle jäävä rivi säilyttää raakakuvauksensa; sulautetut rivit säilytetään kentässä <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Selkeä nimi',
    'generalized_pattern_label' => 'Yleistetty malli',
    'no_prefix_warning' => 'Valituille aliaksille ei löytynyt yhteistä 4 merkin alkuosaa — kirjoita malli käsin ennen vahvistusta.',
    'confirm_merge' => 'Vahvista yhdistäminen',

    'flash' => [
        'updated' => 'Alias päivitetty.',
        'deleted' => 'Alias poistettu.',
        'merged' => 'Aliakset yhdistetty.',
        'imported' => 'Tuotiin :count alias.|Tuotiin :count aliasta.',
        'nothing' => 'Ei mitään tuotavaa.',
    ],

    'errors' => [
        'not_found' => 'Aliasta ei löytynyt (se on ehkä poistettu toisessa välilehdessä).',
        'pattern_empty' => 'Yleistetty malli ei voi olla tyhjä.',
        'select_two' => 'Valitse yhdistettäväksi vähintään kaksi aliasta.',
        'some_not_found' => 'Yhtä tai useampaa valittua aliasta ei löytynyt.',
        'both_required' => 'Sekä selkeä nimi että yleistetty malli vaaditaan.',
        'merge_not_found' => 'Yhtä tai useampaa aliasta ei löytynyt (ne on ehkä poistettu toisessa välilehdessä).',
        'merge_failed' => 'Yhdistäminen epäonnistui (:class).',
        'no_file' => 'Tiedostoa ei lähetetty.',
        'unreadable' => 'Lähetettyä tiedostoa ei voitu lukea.',
        'too_short' => 'Malli on liian lyhyt testattavaksi.',
        'file_not_yaml' => 'Tämä tiedosto ei ole kelvollista YAML:ia, joten siitä ei voitu lukea mitään. Vie aliaksesi uudelleen ja lähetä saamasi tiedosto.',
        'file_unreadable_as_yaml' => 'Tätä tiedostoa ei voitu lukea aliaslistana. Vie aliaksesi uudelleen ja lähetä saamasi tiedosto.',
        'file_has_no_entries_list' => 'Tämä tiedosto ei ala ylimmän tason entries:-listalla, joten siinä ei ole aliaksia tuotavaksi. Tarkista, että valitsit oikean tiedoston.',
        'entry_is_not_a_mapping' => 'Kohta :entry on pelkkä arvo, vaikka odotettiin mallia ja nimeä. Anna sille molemmat kentät tai poista se, ja lähetä tiedosto uudelleen.',
        'entry_is_missing_a_field' => 'Kohdasta :entry puuttuu malli tai nimi, ja alias tarvitsee molemmat. Täydennä puuttuva tai poista kyseinen kohta, ja lähetä tiedosto uudelleen.',
    ],
];
