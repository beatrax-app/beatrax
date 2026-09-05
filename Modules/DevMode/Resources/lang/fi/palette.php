<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Kirjoita hakeaksesi näkymiä, komentoja ja toimintoja. Sulje painamalla Esc.',
    'search_aria' => 'Kirjoita hakeaksesi näkymiä, komentoja ja toimintoja',
    'dialog_aria' => 'Komentopaletti',
    'token_suggest_aria' => 'Token-ehdotukset',
    'rail_view' => 'Näkymä',
    'rail_dev' => 'Dev',
    'rail_action' => 'Toiminto',
    'rail_recent' => 'Viimeisimmät',
    'no_recent' => 'Ei vielä viimeisimpiä valintoja.',
    'section_transactions' => 'Tapahtumat',
    'section_counterparties' => 'Vastapuolet',
    'section_categories' => 'Kategoriat',
    'section_goals_recurring' => 'Tavoitteet ja toistuvat maksut',
    'no_name' => '(ei nimeä)',
    'see_all' => 'Näytä :count tulos →|Näytä kaikki :count tulosta →',
    'no_transactions' => 'Mikään tapahtuma ei vastaa hakua ”:query”',
    'source_txn' => 'tapahtuma',
    'source_counterparty' => 'vastapuoli',
    'source_category' => 'kategoria',
    'results_aria' => 'Tulokset',
    'no_results' => 'Ei tuloksia.',
    'foot_navigate' => 'siirry',
    'foot_select' => 'valitse',
    'foot_close' => 'sulje',
    'close_aria' => 'Sulje haku',
    'close_caption' => 'Sulje',
    'foot_try' => 'Kokeile',
    'results' => ':count tulos|:count tulosta',

    'action' => [
        'run_import' => ['label' => 'Suorita tuonti', 'hint' => 'Avaa ohjattu tuonti'],
        'scan_email' => ['label' => 'Avaa postilaatikot', 'hint' => 'Yhdistetyt postilaatikkosi'],
        // i18n-review: fi · action.open_profile.hint — Finnish says «asetukset» for both
        // Settings and preferences, so the hint would repeat it. «omat valinnat» stands in;
        // a native reader decides whether the repetition reads better.
        'open_profile' => ['label' => 'Avaa profiili', 'hint' => 'Asetukset — tili ja omat valinnat'],
        'toggle_theme' => ['label' => 'Avaa ulkoasuasetukset', 'hint' => 'Vaalea, tumma tai järjestelmä'],
    ],

    'run_command' => 'Suorita :command',

    'nav' => [
        'overview' => ['label' => 'Kehityksen yleiskatsaus', 'hint' => 'Järjestelmäruudut + viimeisimmät suoritukset'],
        'artisan' => ['label' => 'Artisan-suoritin', 'hint' => 'Suorita sallitut komennot'],
        'audit' => ['label' => 'Kehitystilan tarkastusloki', 'hint' => 'Omat kehitystilan toiminnot'],
        'logs' => ['label' => 'Lokien seuranta', 'hint' => 'Reaaliaikainen laravel-*.log-virta'],
        'queue' => ['label' => 'Jonon tarkastelu', 'hint' => 'Odottavat / epäonnistuneet / erät'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Järjestelmätarkistukset'],
        'sql' => ['label' => 'SQL-paneeli', 'hint' => 'Vain SELECT-selain'],
        'system' => ['label' => 'Järjestelmän tilannekuva', 'hint' => 'Ympäristö + polut + asetukset'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Upotettu jononäkymä'],
        'sync_health' => ['label' => 'Synkronoinnin tila', 'hint' => 'Karanteenissa olevat tai ohitetut yhdistämiset'],
    ],
];
