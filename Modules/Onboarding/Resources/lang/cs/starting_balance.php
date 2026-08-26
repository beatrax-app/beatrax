<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 POČÁTEČNÍ ZŮSTATEK',
    'confirmed_aria' => 'potvrzeno',
    'on_date' => 'k :date',

    'detected_h3' => 'Zjištěný počáteční zůstatek — :label',
    'confirm' => 'Potvrdit',
    'edit' => 'Upravit',

    'conflict_h3' => 'U tohoto účtu vidíme dvě hodnoty — která je správná?',
    'conflict_legend' => 'Vyber počáteční zůstatek',
    'conflict_from' => 'Zdroj: :source',
    'conflict_helper' => 'Ve výchozím stavu bereme nejstarší datum. Vyber tu správnou hodnotu nebo ji zadej ručně.',
    'edit_manually' => 'Upravit ručně',

    'editing_h3' => 'Uprav počáteční zůstatek — :label',
    'input_label' => 'POČÁTEČNÍ ZŮSTATEK',
    'minor_units' => '(nejmenší jednotky)',
    'on_date_label' => 'K DATU',
    'cancel' => 'Zrušit',
    'save' => 'Uložit',

    'change' => 'Změnit',

    'manual_h3' => 'Zadej počáteční zůstatek ručně — :label',
    'manual_lede' => 'Počáteční zůstatek tohoto účtu se nepodařilo zjistit automaticky. Zadej ho ručně nebo tento krok přeskoč.',

    'unknown_state' => 'Neznámý stav karty. Načti průvodce znovu.',

    'errors' => [
        'account_not_set' => 'Účet není nastavený. Načti průvodce znovu.',
        'invalid_amount' => 'Zadej platnou částku.',
        'amount_range' => 'Zadej částku od :min do :max.',
        'pick_date' => 'Vyber datum.',
        'pick_valid_date' => 'Vyber platné datum.',
        'future_date' => 'Datum počátečního zůstatku nemůže být v budoucnosti.',
        'date_warning' => 'Je to později než tvá první importovaná transakce (:date). V Přehledu se můžou objevit transakce před tímto datem.',
    ],
];
