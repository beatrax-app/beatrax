<?php

declare(strict_types=1);

return [
    'page_title' => 'Pravila',
    'heading' => 'Pravila',
    'intro' => 'Vnaprej kategoriziraj transakcije ob uvozu. Pravila veljajo za vsak vir — banko, kartico, PayPal in potrdila iz e-pošte.',
    'device_local_note' => 'Pravila ostanejo v tej napravi. Ne delijo se z vašimi drugimi napravami.',

    'reapply' => 'Znova uporabi pravila na zgodovini',
    'reapply_confirm' => 'Znova uporabim vsa pravila na celotni tvoji zgodovini? Vsaka kategorija, nasprotna stranka, opomba in davčna oznaka, ki jo je vpisalo pravilo, se prepiše. Kar je nastavljeno ročno, ostane, prav tako vse na usklajenem izpisku ali na transakciji, ki si jo razdelil. Starih vrednosti nič ne vrne.',
    'reapplying' => 'Ponovna uporaba…',
    'new_rule' => 'Novo pravilo',

    'reapply_progress' => 'Ponovna uporaba pravil… :checked od :count preverjene transakcije|Ponovna uporaba pravil… :checked od :count preverjenih transakcij|Ponovna uporaba pravil… :checked od :count preverjenih transakcij|Ponovna uporaba pravil… :checked od :count preverjenih transakcij',

    'empty_heading' => 'Pravil še ni',
    'empty_body' => 'Pravila prepoznajo transakcije po več pogojih in samodejno uveljavijo spremembe kategorije, nasprotne stranke, opombe in davčne oznake — ob uvozu in vsakič, ko jih znova uporabiš na obstoječi zgodovini.',
    'empty_cta' => 'Ustvari svoje prvo pravilo',

    'col_priority' => 'Prioriteta',
    'col_conditions' => 'Pogoji',
    'col_actions' => 'Dejanja',
    'col_hits' => 'Zadetki',
    'col_created' => 'Ustvarjeno',
    'col_row_actions' => 'Dejanja',
    'inactive_badge' => 'Izklopljeno',
    'combinator_all' => 'VSI',
    'combinator_any' => 'KATERI KOLI',
    'inactive_title' => 'To pravilo se ne izvaja. Pravilo se izklopi, ko se izbriše kategorija ali nasprotna stranka, na katero se sklicuje.',

    'more_conditions' => '+:count več',

    'delete_confirm' => 'Izbrišem?',
    'delete_yes' => 'Da, izbriši',
    'cancel' => 'Prekliči',
    'edit' => 'Uredi',
    'delete' => 'Izbriši',
    'edit_aria' => 'Uredi pravilo (prioriteta :priority)',
    'delete_aria' => 'Izbriši pravilo (prioriteta :priority)',

    'footer_note' => 'Pravila in zgodovina trgovcev delujeta skupaj. Brisanje pravila ne izbriše tega, česar se je Beatrax naučil iz prejšnjih kategorizacij — naslednji uvoz lahko še vedno samodejno predlaga isto kategorijo iz zgodovine.',

    'chip_category' => 'Kategorija: :path',
    'chip_counterparty' => 'Nasprotna stranka: :path',
    'chip_note' => 'Opomba',
    'chip_tax_tag' => 'Davčna oznaka',

    'flash_deleted' => 'Pravilo je izbrisano.',
    'flash_not_found' => 'Pravila ni bilo mogoče najti (morda je bilo izbrisano v drugem zavihku).',
    'flash_saved' => 'Pravilo je shranjeno.',
    'flash_reapplying' => 'Ponovna uporaba pravil na zgodovini…',
    'summary_no_changes' => 'Ni sprememb — tvoja zgodovina že ustreza pravilom.',
    'summary_updated' => 'Posodobljeno: :fields, :transactions.',
    'summary_fields' => ':count polje|:count polji|:count polja|:count polj',
    'summary_transactions' => ':count transakcija|:count transakciji|:count transakcije|:count transakcij',
    'summary_reconciled_skipped' => 'Preskočena je bila :count usklajena transakcija.|Preskočeni sta bili :count usklajeni transakciji.|Preskočene so bile :count usklajene transakcije.|Preskočenih je bilo :count usklajenih transakcij.',
];
