<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasi',
    'heading' => 'Aliasi',
    'subtitle' => 'Razumljivi nazivi koje si zadao Beatraxu za nejasne opise s tvojih izvoda. Uredi generalizirani uzorak u retku da proširiš ili suziš koje druge transakcije nasljeđuju isti razumljiv naziv.',
    'dismiss' => 'odbaci',

    'selected_count' => ':count odabrano',
    'merge_selected' => 'Spoji odabrano',

    'empty_heading' => 'Još nema aliasa',
    'empty_body' => 'Aliasi se pojavljuju ovdje nakon što klikneš kurzivni izvorni opis u retku pregleda uvoza i daš mu razumljiv naziv.',
    // i18n-review: hr · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliasi se pojavljuju ovdje nakon što dodirneš kurzivni izvorni opis u retku pregleda uvoza i daš mu razumljiv naziv.',

    'col_select' => 'Odaberi',
    'col_raw' => 'Izvorni opis',
    'col_generalized' => 'Generalizirani uzorak',
    'col_friendly' => 'Razumljiv naziv',
    'col_actions' => 'Radnje',

    'select_alias_aria' => 'Odaberi alias :name',
    'generalized_pattern_aria' => 'Generalizirani uzorak',

    'save' => 'Spremi',
    'cancel' => 'Odustani',
    'edit' => 'Uredi',
    'delete' => 'Izbriši',
    'delete_confirm' => "Izbrisati ovaj alias? Budući uvozi za ':pattern' vraćaju se na izvorni opis.",

    'backup_transfer' => 'Sigurnosna kopija i prijenos',
    'export_yaml' => 'Izvezi aliase kao YAML',

    'export_help_html' => 'Preuzima <code class="font-mono">aliases.yaml</code> u formatu korpusa zajednice.',
    'import_from_yaml' => 'Uvezi iz YAML-a',
    'parse_preview' => 'Obradi i pregledaj',
    'cancel_import' => 'Odustani od uvoza',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: hr · diff_new, diff_unchanged — the elided noun is alias, so the
    // first arm is written definite (novi) and the paucal takes the genitive
    // singular. Whether the indefinite nov reads better at one is open.
    'diff_new' => ':count novi|:count nova|:count novih',
    'diff_unchanged' => ':count nepromijenjeni|:count nepromijenjena|:count nepromijenjenih',
    'diff_conflicts' => ':count sukob|:count sukoba|:count sukoba',

    'conflicts_heading' => 'Sukobi',
    'conflict_name' => 'naziv — postojeće: :existing → datoteka: :file',
    'conflict_pattern_existing' => 'uzorak — postojeće:',
    'conflict_file' => '→ datoteka:',
    'resolution_for_aria' => 'Rješenje za :pattern',
    'keep_yours' => 'Zadrži svoje',
    'replace' => 'Zamijeni',
    'confirm_import' => 'Potvrdi uvoz',

    'preview_aria' => 'Pregled u odnosu na transakcije',
    'test_heading' => 'Testiraj na mojim transakcijama',
    'test_help' => 'Uredi generalizirani uzorak u retku da vidiš kojim bi transakcijama odgovarao.',
    'typing' => 'Tipkanje…',
    'matches' => 'Odgovara :count transakciji u tvojoj nedavnoj povijesti.|Odgovara :count transakcijama u tvojoj nedavnoj povijesti.|Odgovara :count transakcijama u tvojoj nedavnoj povijesti.',

    'merge_modal_title' => 'Spoji :count alias|Spoji :count aliasa|Spoji :count aliasa',

    'merge_modal_help_html' => 'Preostali redak zadržava svoj izvorni opis; apsorbirani retci čuvaju se u <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Razumljiv naziv',
    'generalized_pattern_label' => 'Generalizirani uzorak',
    'no_prefix_warning' => 'Među odabranim aliasima nije pronađen zajednički prefiks od 4 znaka — prije potvrde ručno upiši uzorak.',
    'confirm_merge' => 'Potvrdi spajanje',

    'flash' => [
        'updated' => 'Alias ažuriran.',
        'deleted' => 'Alias izbrisan.',
        'merged' => 'Aliasi spojeni.',
        'imported' => 'Uvezen :count alias.|Uvezena :count aliasa.|Uvezeno :count aliasa.',
        'nothing' => 'Nema ništa za uvoz.',
    ],

    'errors' => [
        'not_found' => 'Alias nije pronađen (možda je izbrisan u drugoj kartici).',
        'pattern_empty' => 'Generalizirani uzorak ne može biti prazan.',
        'select_two' => 'Odaberi barem dva aliasa za spajanje.',
        'some_not_found' => 'Jedan ili više odabranih aliasa nije pronađeno.',
        'both_required' => 'Razumljiv naziv i generalizirani uzorak su obavezni.',
        'merge_not_found' => 'Jedan ili više aliasa nije pronađeno (možda su izbrisani u drugoj kartici).',
        'merge_failed' => 'Spajanje nije uspjelo (:class).',
        'no_file' => 'Nijedna datoteka nije učitana.',
        'unreadable' => 'Učitanu datoteku nije moguće pročitati.',
        'too_short' => 'Uzorak je prekratak za testiranje.',
        'file_not_yaml' => 'Ova datoteka nije valjani YAML, pa iz nje nije bilo moguće ništa pročitati. Izvezi svoje aliase ponovno i učitaj dobivenu datoteku.',
        'file_unreadable_as_yaml' => 'Ovu datoteku nije bilo moguće pročitati kao popis aliasa. Izvezi svoje aliase ponovno i učitaj dobivenu datoteku.',
        'file_has_no_entries_list' => 'Ova datoteka ne počinje popisom entries: na najvišoj razini, pa u njoj nema aliasa za uvoz. Provjeri je li to prava datoteka.',
        'entry_is_not_a_mapping' => 'Unos :entry je obična vrijednost ondje gdje su očekivani uzorak i naziv. Dodaj mu oba polja ili ga ukloni, pa ponovno učitaj datoteku.',
        'entry_is_missing_a_field' => 'Unosu :entry nedostaje uzorak ili naziv, a alias treba oboje. Dopuni ono što nedostaje ili ukloni taj unos, pa ponovno učitaj datoteku.',
    ],
];
