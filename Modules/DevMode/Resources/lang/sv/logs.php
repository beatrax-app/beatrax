<?php

declare(strict_types=1);

return [
    'heading' => 'Loggar',
    'subtitle' => 'Live-tail av dagens Laravel-loggfil med dubbel maskering både vid skrivning och vid strömning.',
    'truncate' => 'Töm',
    'truncate_confirm' => 'Töm dagens loggfil? Det går inte att ångra.',
    'truncate_title' => 'Töm dagens loggfil (behåller inoden så att tailern fortsätter utan avbrott)',
    'filters_aria' => 'Loggfilter',
    'severity_aria' => 'Allvarlighetsfilter',
    'channel_placeholder' => 'Kanalfilter…',
    'channel_aria' => 'Kanalfilter',
    'contains_placeholder' => 'Sök bland synliga…',
    'contains_aria' => 'Innehåller-filter',
    'pause' => 'Pausa',
    'resume' => 'Återuppta',
    'waiting' => 'Väntar på loggrader…',
    'copy' => 'Kopiera',
    'copy_title' => 'Kopiera hela posten',
    'copy_title_copied' => 'Kopierat',
    'copy_aria' => 'Kopiera loggpost',
    'copy_aria_copied' => 'Kopierat till urklipp',
    'dismiss' => 'Dölj',
    'dismiss_title' => 'Dölj från vyn (ändrar inte loggfilen)',
    'dismiss_aria' => 'Dölj loggposten från vyn',
    'totals' => [
        'showing' => 'Visar :shown av :count mottagen rad (buffertgräns :cap)|Visar :shown av :count mottagna rader (buffertgräns :cap)',
        'lines_today' => ':count rad i dag|:count rader i dag',
        'lines_today_capped' => 'över :count rad i dag|över :count rader i dag',
        'today' => 'i dag',
        'all_files' => ':size fördelat på :count daglig fil|:size fördelat på :count dagliga filer',
    ],

    'status' => [
        'poll_interrupted' => 'Loggpollningen avbröts. Försöker igen…',
        'paused' => 'Pausad.',
        'copy_failed_prefix' => 'Kopieringen misslyckades: ',
        'clipboard_unavailable' => 'urklipp inte tillgängligt',
    ],

    'toast' => [
        'truncated' => 'Loggen tömd — frigjorde :size.',
        'nothing' => 'Inget att tömma.',
    ],
];
