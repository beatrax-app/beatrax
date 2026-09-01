<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasi',
    'heading' => 'Aliasi',
    'subtitle' => 'Razumljiva imena, ki si jih dal Beatraxu za nejasne opise na tvojih izpiskih. Uredi posplošeni vzorec v vrstici, da razširiš ali zožiš, katere druge transakcije podedujejo isto razumljivo ime.',
    'dismiss' => 'opusti',

    'selected_count' => ':count izbranih',
    'merge_selected' => 'Združi izbrano',

    'empty_heading' => 'Aliasov še ni',
    'empty_body' => 'Aliasi se pojavijo tukaj, ko klikneš ležeči izvorni opis v vrstici predogleda uvoza in mu daš razumljivo ime.',
    // i18n-review: sl · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Aliasi se pojavijo tukaj, ko tapneš ležeči izvorni opis v vrstici predogleda uvoza in mu daš razumljivo ime.',

    'col_select' => 'Izberi',
    'col_raw' => 'Izvorni opis',
    'col_generalized' => 'Posplošeni vzorec',
    'col_friendly' => 'Razumljivo ime',
    'col_actions' => 'Dejanja',

    'select_alias_aria' => 'Izberi alias :name',
    'generalized_pattern_aria' => 'Posplošeni vzorec',

    'save' => 'Shrani',
    'cancel' => 'Prekliči',
    'edit' => 'Uredi',
    'delete' => 'Izbriši',
    'delete_confirm' => "Izbrišem ta alias? Prihodnji uvozi za ':pattern' se bodo vrnili na izvorni opis.",

    'backup_transfer' => 'Varnostna kopija in prenos',
    'export_yaml' => 'Izvozi aliase kot YAML',

    'export_help_html' => 'Prenese <code class="font-mono">aliases.yaml</code> v obliki korpusa skupnosti.',
    'import_from_yaml' => 'Uvozi iz YAML',
    'parse_preview' => 'Razčleni in predogled',
    'cancel_import' => 'Prekliči uvoz',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: sl · diff_new, diff_unchanged — indefinite masculine across all
    // four arms for an elided alias, which is what gives the dual nova. The
    // definite novi is the alternative a reader may expect in a diff caption.
    'diff_new' => ':count nov|:count nova|:count novi|:count novih',
    'diff_unchanged' => ':count nespremenjen|:count nespremenjena|:count nespremenjeni|:count nespremenjenih',
    'diff_conflicts' => ':count spor|:count spora|:count spori|:count sporov',

    'conflicts_heading' => 'Spori',
    'conflict_name' => 'ime — obstoječe: :existing → datoteka: :file',
    'conflict_pattern_existing' => 'vzorec — obstoječe:',
    'conflict_file' => '→ datoteka:',
    'resolution_for_aria' => 'Razrešitev za :pattern',
    'keep_yours' => 'Obdrži svoje',
    'replace' => 'Zamenjaj',
    'confirm_import' => 'Potrdi uvoz',

    'preview_aria' => 'Predogled glede na transakcije',
    'test_heading' => 'Preizkusi na mojih transakcijah',
    'test_help' => 'Uredi posplošeni vzorec v vrstici, da vidiš, katerim transakcijam bi ustrezal.',
    'typing' => 'Tipkanje…',
    'matches' => 'Ustreza :count transakciji v tvoji nedavni zgodovini.|Ustreza :count transakcijama v tvoji nedavni zgodovini.|Ustreza :count transakcijam v tvoji nedavni zgodovini.|Ustreza :count transakcijam v tvoji nedavni zgodovini.',

    'merge_modal_title' => 'Združi :count alias|Združi :count aliasa|Združi :count aliase|Združi :count aliasov',

    'merge_modal_help_html' => 'Preostala vrstica obdrži svoj izvorni opis; vsrkane vrstice se ohranijo v <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Razumljivo ime',
    'generalized_pattern_label' => 'Posplošeni vzorec',
    'no_prefix_warning' => 'Med izbranimi aliasi ni bilo skupne predpone štirih znakov — pred potrditvijo ročno vnesi vzorec.',
    'confirm_merge' => 'Potrdi združitev',

    'flash' => [
        'updated' => 'Alias posodobljen.',
        'deleted' => 'Alias izbrisan.',
        'merged' => 'Aliasi združeni.',
        'imported' => 'Uvožen :count alias.|Uvožena :count aliasa.|Uvoženi :count aliasi.|Uvoženih :count aliasov.',
        'nothing' => 'Ni ničesar za uvoz.',
    ],

    'errors' => [
        'not_found' => 'Aliasa ni bilo mogoče najti (morda je bil izbrisan v drugem zavihku).',
        'pattern_empty' => 'Posplošeni vzorec ne sme biti prazen.',
        'select_two' => 'Za združitev izberi vsaj dva aliasa.',
        'some_not_found' => 'Enega ali več izbranih aliasov ni bilo mogoče najti.',
        'both_required' => 'Razumljivo ime in posplošeni vzorec sta oba obvezna.',
        'merge_not_found' => 'Enega ali več aliasov ni bilo mogoče najti (morda so bili izbrisani v drugem zavihku).',
        'merge_failed' => 'Združitev ni uspela (:class).',
        'no_file' => 'Nobena datoteka ni bila naložena.',
        'unreadable' => 'Naložene datoteke ni bilo mogoče prebrati.',
        'too_short' => 'Vzorec je prekratek za preizkus.',
        'file_not_yaml' => 'Ta datoteka ni veljaven YAML, zato iz nje ni bilo mogoče prebrati ničesar. Znova izvozi svoje aliase in naloži dobljeno datoteko.',
        'file_unreadable_as_yaml' => 'Te datoteke ni bilo mogoče prebrati kot seznam aliasov. Znova izvozi svoje aliase in naloži dobljeno datoteko.',
        'file_has_no_entries_list' => 'Ta datoteka se ne začne s seznamom entries: na najvišji ravni, zato v njej ni aliasov za uvoz. Preveri, ali je to prava datoteka.',
        'entry_is_not_a_mapping' => 'Vnos :entry je preprosta vrednost tam, kjer sta bila pričakovana vzorec in ime. Dodaj mu obe polji ali ga odstrani in znova naloži datoteko.',
        'entry_is_missing_a_field' => 'Vnosu :entry manjka vzorec ali ime, alias pa potrebuje oboje. Dopolni, kar manjka, ali odstrani ta vnos in znova naloži datoteko.',
    ],
];
