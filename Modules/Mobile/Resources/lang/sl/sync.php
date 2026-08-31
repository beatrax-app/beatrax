<?php

declare(strict_types=1);

return [
    'page_title' => 'Podatki in naprave',
    'heading' => 'Podatki in naprave',
    'sync_status' => 'Stanje sinhronizacije',
    'syncing_progress' => 'Sinhronizacija… :count zapis|Sinhronizacija… :count zapisa|Sinhronizacija… :count zapisi|Sinhronizacija… :count zapisov',
    'initial_sync_aria' => 'Napredek začetne sinhronizacije',
    'no_peers' => 'Seznani drugo napravo, da se sinhronizacija začne.',
    'sync_now' => 'Sinhroniziraj zdaj',
    'result' => [
        'synced' => 'Sinhronizirano z drugo napravo.',
        'unreachable' => 'Druge naprave ni bilo mogoče doseči — preveri, ali sta obe v istem omrežju.',
        'locked' => 'Odkleni aplikacijo za sinhronizacijo.',
        'not_enabled' => 'Sinhronizacija na tej napravi še ni nastavljena.',
        'unreadable' => 'Ključa te naprave ni več mogoče odpreti. Znova poveži napravi, da nadaljuješ sinhronizacijo.',
        'paused_on_cellular' => 'Zaustavljeno — sinhronizacija je omejena na Wi-Fi, ti pa si na mobilnih podatkih.',
    ],
    'background_note' => 'Sinhronizacija se zgodi, ko se dotakneš Sinhroniziraj zdaj. V ozadju ne more teči — zaklep aplikacije hrani edini ključ.',
    'network' => 'Omrežje',
    'pause_cellular' => 'Ustavi sinhronizacijo prek mobilnega omrežja',
    'pause_cellular_help' => 'Privzeto izklopljeno — sinhronizacija deluje povsod. Vklopi, da se sinhronizira samo prek Wi-Fi.',
];
