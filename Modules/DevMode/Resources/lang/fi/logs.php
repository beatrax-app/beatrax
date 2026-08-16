<?php

declare(strict_types=1);

return [
    'heading' => 'Lokit',
    'subtitle' => 'Kuluvan päivän Laravel-lokitiedoston reaaliaikainen seuranta, jossa arkaluonteiset tiedot peitetään varmuuden vuoksi sekä kirjoitettaessa että striimattaessa.',
    'truncate' => 'Tyhjennä',
    'truncate_confirm' => 'Tyhjennetäänkö tämän päivän lokitiedosto? Tätä ei voi kumota.',
    'truncate_title' => 'Tyhjennä tämän päivän lokitiedosto (säilyttää inoden, jotta seuranta jatkuu puhtaasti)',
    'filters_aria' => 'Lokisuodattimet',
    'severity_aria' => 'Vakavuussuodatin',
    'channel_placeholder' => 'Kanavasuodatin…',
    'channel_aria' => 'Kanavasuodatin',
    'contains_placeholder' => 'Hae näkyvistä…',
    'contains_aria' => 'Sisältää-suodatin',
    'pause' => 'Keskeytä',
    'resume' => 'Jatka',
    'waiting' => 'Odotetaan lokirivejä…',
    'copy' => 'Kopioi',
    'copy_title' => 'Kopioi koko merkintä',
    'copy_title_copied' => 'Kopioitu',
    'copy_aria' => 'Kopioi lokimerkintä',
    'copy_aria_copied' => 'Kopioitu leikepöydälle',
    'dismiss' => 'Ohita',
    'dismiss_title' => 'Piilota näkymästä (ei muuta lokitiedostoa)',
    'dismiss_aria' => 'Piilota lokimerkintä näkymästä',
    'totals' => [
        'showing' => 'Näytetään',
        'of' => '/',
        'received' => 'vastaanotettu (puskurin katto 10k)',
        'lines_today' => 'riviä tänään',
        'today' => 'tänään',
        'across' => 'yhteensä',
        'daily_files' => 'päivätiedostossa',
    ],

    'status' => [
        'poll_interrupted' => 'Lokin kysely keskeytyi. Yritetään uudelleen…',
        'paused' => 'Keskeytetty.',
        'copy_failed_prefix' => 'Kopiointi epäonnistui: ',
        'clipboard_unavailable' => 'leikepöytä ei ole käytettävissä',
    ],

    'toast' => [
        'truncated' => 'Loki tyhjennetty — vapautui :size.',
        'nothing' => 'Ei tyhjennettävää.',
    ],
];
