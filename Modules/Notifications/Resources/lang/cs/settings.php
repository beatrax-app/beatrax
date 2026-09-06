<?php

declare(strict_types=1);

return [
    'what_heading' => 'O čem posílat oznámení',
    'background_note' => 'Beatrax je připraví, když je aplikace otevřená. Naplánovaný běh na pozadí to nezvládne — zámek aplikace drží jediný klíč — takže čekající se doplní, jak dál aplikaci používáš.',
    'background_note_phone' => 'Beatrax je připraví, když je aplikace otevřená. Na pozadí to nejde — zámek aplikace drží jediný klíč — takže čekající dorazí, až aplikaci příště otevřeš.',
    'system_grant_refused' => 'Tvoje zařízení nedovoluje Beatraxu zobrazovat oznámení, takže se k tobě nic z toho níže nedostane. Zapni je pro Beatrax v nastavení zařízení.',

    'reminders' => [
        'label' => 'Připomínky plateb',
        'help' => 'Dostaneš avízo, než bude opakovaná platba splatná.',
    ],

    'lead_days' => [
        'label' => 'Připomenout ___ dní předem',
        'help' => 'Kolik dní před splatností se připomínka objeví. 1–30 dní.',
    ],

    'budget_nudges' => [
        'label' => 'Upozornění na rozpočet',
        'help' => 'Dáme ti vědět, když je rozpočet kategorie skoro vyčerpaný.',
    ],

    'digest' => [
        'label' => 'Tvá situace',
        'help' => 'Jak často dostaneš souhrn toho, jak na tom v tomto období jsi.',
        'daily' => 'Denně',
        'weekly' => 'Týdně',
        'off' => 'Vypnuto',
    ],

    'savings' => [
        'label' => 'Tipy na úspory',
        'help' => 'Dáme ti vědět, když Beatrax najde levnější tarif nebo místo, kde se dá ušetřit.',
    ],

    'when_heading' => 'Kdy a jak',

    'quiet_hours' => [
        'label' => 'Hodiny klidu',
        'help' => 'V tomto okně žádný zvuk ani banner — oznámení ti stejně dorazí do schránky.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Skrýt podrobnosti v oznámeních',
        'help' => 'Skrývat částky a jména obchodníků přímo v banneru oznámení. Zapni to, pokud ti na obrazovku můžou vidět ostatní.',
    ],

    'save' => 'Uložit nastavení oznámení',
    'saved' => 'Uloženo.',

    'other_devices' => [
        'summary' => 'Ostatní zařízení',
        'empty' => 'Zatím nejsou spárovaná žádná další zařízení.',
        'unnamed' => 'Nepojmenované zařízení',

        'summary_line' => 'připomínky :reminders · upozornění :nudges · souhrn :digest · úspory :savings',
        'on' => 'zap.',
        'off' => 'vyp.',
    ],

    'errors' => [
        'save_failed' => 'Nastavení oznámení se nepodařilo uložit. Zkus to znovu.',
    ],
];
