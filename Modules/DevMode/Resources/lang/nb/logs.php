<?php

declare(strict_types=1);

return [
    'heading' => 'Logger',
    'subtitle' => 'Live-tail av dagens Laravel-loggfil med dobbel maskering både ved skriving og ved strømming.',
    'truncate' => 'Tøm',
    'truncate_confirm' => 'Tømme dagens loggfil? Dette kan ikke angres.',
    'truncate_title' => 'Tøm dagens loggfil (beholder inoden slik at taileren fortsetter uten avbrudd)',
    'filters_aria' => 'Loggfiltre',
    'severity_aria' => 'Alvorlighetsfilter',
    'channel_placeholder' => 'Kanalfilter…',
    'channel_aria' => 'Kanalfilter',
    'contains_placeholder' => 'Søk i synlige…',
    'contains_aria' => 'Inneholder-filter',
    'pause' => 'Pause',
    'resume' => 'Fortsett',
    'waiting' => 'Venter på logglinjer…',
    'copy' => 'Kopier',
    'copy_title' => 'Kopier hele oppføringen',
    'copy_title_copied' => 'Kopiert',
    'copy_aria' => 'Kopier loggoppføring',
    'copy_aria_copied' => 'Kopiert til utklippstavlen',
    'dismiss' => 'Skjul',
    'dismiss_title' => 'Skjul fra visningen (endrer ikke loggfilen)',
    'dismiss_aria' => 'Skjul loggoppføringen fra visningen',
    'totals' => [
        'showing' => 'Viser :shown av :count mottatt linje (buffergrense :cap)|Viser :shown av :count mottatte linjer (buffergrense :cap)',
        'lines_today' => ':count linje i dag|:count linjer i dag',
        'lines_today_capped' => 'over :count linje i dag|over :count linjer i dag',
        'today' => 'i dag',
        'all_files' => ':size fordelt på :count daglig fil|:size fordelt på :count daglige filer',
    ],

    'status' => [
        'poll_interrupted' => 'Logg-pollingen ble avbrutt. Prøver igjen…',
        'paused' => 'Satt på pause.',
        'copy_failed_prefix' => 'Kopieringen mislyktes: ',
        'clipboard_unavailable' => 'utklippstavlen er ikke tilgjengelig',
    ],

    'toast' => [
        'truncated' => 'Loggen ble tømt — frigjorde :size.',
        'nothing' => 'Ingenting å tømme.',
    ],
];
