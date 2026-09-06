<?php

declare(strict_types=1);

return [
    'what_heading' => 'O čem naj te obveščam',
    'background_note' => 'Beatrax jih pripravi, ko je aplikacija odprta. Načrtovani zagon v ozadju tega ne zmore — zaklep aplikacije hrani edini ključ — zato se čakajoča prevzamejo, medtem ko aplikacijo uporabljaš naprej.',
    'background_note_phone' => 'Beatrax jih pripravi, ko je aplikacija odprta. V ozadju to ne gre — zaklep aplikacije hrani edini ključ — zato čakajoča prispejo, ko aplikacijo naslednjič odpreš.',
    'system_grant_refused' => 'Tvoja naprava Beatraxu ne dovoli prikazovati obvestil, zato te nič od spodnjega ne doseže. Vklopi jih za Beatrax v nastavitvah naprave.',

    'reminders' => [
        'label' => 'Opomniki za plačila',
        'help' => 'Javi mi, preden zapade ponavljajoče se plačilo.',
    ],

    'lead_days' => [
        'label' => 'Opomni me ___ dni prej',
        'help' => 'Koliko dni pred datumom zapadlosti se sproži opomnik. 1–30 dni.',
    ],

    'budget_nudges' => [
        'label' => 'Opozorila o proračunu',
        'help' => 'Javi mi, ko je proračun kategorije skoraj porabljen.',
    ],

    'digest' => [
        'label' => 'Tvoj položaj',
        'help' => 'Kako pogosto dobiš povzetek stanja v tem obdobju.',
        'daily' => 'Dnevno',
        'weekly' => 'Tedensko',
        'off' => 'Izklopljeno',
    ],

    'savings' => [
        'label' => 'Predlogi za prihranke',
        'help' => 'Javi mi, ko Beatrax opazi cenejši paket ali mesto, kjer bi lahko prihranil.',
    ],

    'when_heading' => 'Kdaj in kako',

    'quiet_hours' => [
        'label' => 'Tihe ure',
        'help' => 'V tem obdobju ni zvoka ne pasice — obvestila še vedno pristanejo v tvojem nabiralniku.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Skrij podrobnosti v obvestilih',
        'help' => 'Zneske in imena trgovcev skrij kar v pasici obvestila. Vklopi, če bi tvoj zaslon lahko videli drugi.',
    ],

    'save' => 'Shrani nastavitve obvestil',
    'saved' => 'Shranjeno.',

    'other_devices' => [
        'summary' => 'Druge naprave',
        'empty' => 'Drugih seznanjenih naprav še ni.',
        'unnamed' => 'Neimenovana naprava',

        'summary_line' => 'opomniki :reminders · opozorila :nudges · povzetek :digest · prihranki :savings',
        'on' => 'vklopljeno',
        'off' => 'izklopljeno',
    ],

    'errors' => [
        'save_failed' => 'Tvojih nastavitev obvestil ni bilo mogoče shraniti. Poskusi znova.',
    ],
];
