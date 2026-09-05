<?php

declare(strict_types=1);

return [
    'page_title' => 'Třídění protistran',
    'heading' => 'Roztřiď neznámé protistrany',

    'progress' => ':seen z :total · :percent % · zbývá ~:minutes min',
    'progress_aria' => 'Průběh třídění',

    'all_caught_aria' => 'Všechny protistrany označeny',
    'all_caught_heading' => '🎉 Hotovo — každá protistrana má označení.',
    'back_to_index' => 'Zpět na protistrany →',

    'meta' => ':count transakce · naposledy :date|:count transakce · naposledy :date|:count transakcí · naposledy :date',

    'suggested_aria' => 'Navržená shoda',
    'suggestion_medium' => '✨ Možná **:name** — střední jistota',
    'suggestion_low' => 'Shoda vzorce: **:name** — nízká jistota. Před propojením ověř.',
    'suggestion_high' => '✨ Vypadá to na **:name** — vysoká jistota',

    'reasoning' => ':hits z :total nedávné transakce na tomto IBAN ukazuje na :name.|:hits z :total nedávných transakcí na tomto IBAN ukazuje na :name.|:hits z :total nedávných transakcí na tomto IBAN ukazuje na :name.',
    'yes_link' => 'Ano, propojit s: :name ↵',
    'no_not' => 'Ne, není to :name',

    'recent_on_iban' => 'Nedávné transakce na tomto IBAN',
    'recent_on_counterparty' => 'Nedávné transakce s touto protistranou',
    'no_transactions_yet' => 'Zatím žádné transakce.',

    'label_manually' => 'Nebo označ ručně',
    'label_question' => 'Co je tato protistrana?',
    'display_name_label' => 'Zobrazované jméno',
    'type_label' => 'Typ',
    'type_merchant' => 'Obchodník',
    'type_personal' => 'Soukromá osoba',
    'type_bank' => 'Banka',
    'type_government' => 'Úřad',
    'save_label' => 'Uložit označení',
    'name_required' => 'Nejdřív dej této protistraně jméno.',
    'draft_kept' => 'To, co napíšeš, zůstane zachováno, když se posouváš frontou.',

    'skip' => 'Zatím přeskočit',
    'mark_ignored' => 'Už se na tuto neptat',
    'skip_note' => 'Přeskočení nic nezapisuje — jen se posune na další neznámou.',
    'mark_ignored_note' => 'Tímto se protistrana označí jako ignorovaná, takže z této fronty zmizí. Její název, typ i historie zůstanou beze změny a pořád ji můžeš označit později na stránce Protistrany.',
    'previous' => 'Předchozí neznámá',

    'kbd_yes' => 'ano',
    'kbd_no' => 'ne',
    'kbd_skip' => 'přeskočit',
    'kbd_next' => 'další',

    'footer' => 'Označeno: :seen · zbývá: :count',
];
