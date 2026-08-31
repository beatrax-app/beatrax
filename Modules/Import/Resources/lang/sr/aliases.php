<?php

declare(strict_types=1);

return [
    'page_title' => 'Alijasi',
    'heading' => 'Alijasi',
    'subtitle' => 'Razumljivi nazivi koje si zadao Beatraxu za nejasne opise sa tvojih izvoda. Izmeni generalizovani obrazac u redu da proširiš ili suziš koje druge transakcije nasleđuju isti razumljiv naziv.',
    'dismiss' => 'odbaci',

    'selected_count' => ':count izabrano',
    'merge_selected' => 'Spoji izabrano',

    'empty_heading' => 'Još nema alijasa',
    'empty_body' => 'Alijasi se pojavljuju ovde nakon što klikneš kurzivni izvorni opis u redu pregleda uvoza i daš mu razumljiv naziv.',
    // i18n-review: sr · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Alijasi se pojavljuju ovde nakon što dodirneš kurzivni izvorni opis u redu pregleda uvoza i daš mu razumljiv naziv.',

    'col_select' => 'Izaberi',
    'col_raw' => 'Izvorni opis',
    'col_generalized' => 'Generalizovani obrazac',
    'col_friendly' => 'Razumljiv naziv',
    'col_actions' => 'Radnje',

    'select_alias_aria' => 'Izaberi alijas :name',
    'generalized_pattern_aria' => 'Generalizovani obrazac',

    'save' => 'Sačuvaj',
    'cancel' => 'Otkaži',
    'edit' => 'Izmeni',
    'delete' => 'Obriši',
    'delete_confirm' => "Obrisati ovaj alijas? Budući uvozi za ':pattern' vraćaju se na izvorni opis.",

    'backup_transfer' => 'Rezervna kopija i prenos',
    'export_yaml' => 'Izvezi alijase kao YAML',

    'export_help_html' => 'Preuzima <code class="font-mono">aliases.yaml</code> u formatu korpusa zajednice.',
    'import_from_yaml' => 'Uvezi iz YAML-a',
    'parse_preview' => 'Obradi i pregledaj',
    'cancel_import' => 'Otkaži uvoz',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: sr · diff_new, diff_unchanged — same call as the Croatian file:
    // the first arm is the definite novi against the indefinite nov, with the
    // paucal in the genitive singular of the elided alijas.
    'diff_new' => ':count novi|:count nova|:count novih',
    'diff_unchanged' => ':count nepromenjeni|:count nepromenjena|:count nepromenjenih',
    'diff_conflicts' => ':count sukob|:count sukoba|:count sukoba',

    'conflicts_heading' => 'Sukobi',
    'conflict_name' => 'naziv — postojeće: :existing → datoteka: :file',
    'conflict_pattern_existing' => 'obrazac — postojeće:',
    'conflict_file' => '→ datoteka:',
    'resolution_for_aria' => 'Rešenje za :pattern',
    'keep_yours' => 'Zadrži svoje',
    'replace' => 'Zameni',
    'confirm_import' => 'Potvrdi uvoz',

    'preview_aria' => 'Pregled u odnosu na transakcije',
    'test_heading' => 'Testiraj na mojim transakcijama',
    'test_help' => 'Izmeni generalizovani obrazac u redu da vidiš kojim bi transakcijama odgovarao.',
    'typing' => 'Kucanje…',
    'matches' => 'Odgovara :count transakciji u tvojoj skorašnjoj istoriji.|Odgovara :count transakcijama u tvojoj skorašnjoj istoriji.|Odgovara :count transakcijama u tvojoj skorašnjoj istoriji.',

    'merge_modal_title' => 'Spoji :count alijas|Spoji :count alijasa|Spoji :count alijasa',

    'merge_modal_help_html' => 'Preostali red zadržava svoj izvorni opis; apsorbovani redovi čuvaju se u <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Razumljiv naziv',
    'generalized_pattern_label' => 'Generalizovani obrazac',
    'no_prefix_warning' => 'Među izabranim alijasima nije pronađen zajednički prefiks od 4 znaka — pre potvrde ručno upiši obrazac.',
    'confirm_merge' => 'Potvrdi spajanje',

    'flash' => [
        'updated' => 'Alijas ažuriran.',
        'deleted' => 'Alijas obrisan.',
        'merged' => 'Alijasi spojeni.',
        'imported' => 'Uvezen :count alijas.|Uvezena :count alijasa.|Uvezeno :count alijasa.',
        'nothing' => 'Nema ništa za uvoz.',
    ],

    'errors' => [
        'not_found' => 'Alijas nije pronađen (možda je obrisan u drugoj kartici).',
        'pattern_empty' => 'Generalizovani obrazac ne može biti prazan.',
        'select_two' => 'Izaberi bar dva alijasa za spajanje.',
        'some_not_found' => 'Jedan ili više izabranih alijasa nije pronađeno.',
        'both_required' => 'Razumljiv naziv i generalizovani obrazac su obavezni.',
        'merge_not_found' => 'Jedan ili više alijasa nije pronađeno (možda su obrisani u drugoj kartici).',
        'merge_failed' => 'Spajanje nije uspelo (:class).',
        'no_file' => 'Nijedna datoteka nije otpremljena.',
        'unreadable' => 'Otpremljenu datoteku nije moguće pročitati.',
        'too_short' => 'Obrazac je prekratak za testiranje.',
        'file_not_yaml' => 'Ova datoteka nije važeći YAML, pa iz nje nije bilo moguće ništa pročitati. Izvezi svoje alijase ponovo i otpremi dobijenu datoteku.',
        'file_unreadable_as_yaml' => 'Ovu datoteku nije bilo moguće pročitati kao spisak alijasa. Izvezi svoje alijase ponovo i otpremi dobijenu datoteku.',
        'file_has_no_entries_list' => 'Ova datoteka ne počinje spiskom entries: na najvišem nivou, pa u njoj nema alijasa za uvoz. Proveri da li je to prava datoteka.',
        'entry_is_not_a_mapping' => 'Unos :entry je obična vrednost tamo gde su očekivani obrazac i naziv. Dodaj mu oba polja ili ga ukloni, pa ponovo otpremi datoteku.',
        'entry_is_missing_a_field' => 'Unosu :entry nedostaje obrazac ili naziv, a alijas treba oboje. Dopuni ono što nedostaje ili ukloni taj unos, pa ponovo otpremi datoteku.',
    ],
];
