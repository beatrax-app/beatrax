<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Järjestelmähälytykset',

    'actions' => [
        'install_next_launch' => 'Asenna seuraavalla käynnistyksellä',
        'install_next_launch_aria' => 'Asenna seuraavalla käynnistyksellä — merkitsee järjestelmähälytyksen #:id ratkaistuksi',
        'skip_version' => 'Ohita tämä versio',
        'release_notes' => 'Julkaisutiedot →',
        'update_now' => 'Päivitä nyt',
        'update_now_aria' => 'Päivitä nyt — merkitsee järjestelmähälytyksen #:id ratkaistuksi',
        'remind_later' => 'Muistuta myöhemmin',
        'mark_resolved' => 'Merkitse ratkaistuksi',
        'mark_resolved_aria' => 'Merkitse ratkaistuksi — järjestelmähälytys #:id',
    ],

    'messages' => [
        'update_available' => 'Päivitys saatavilla — Beatrax :version on valmiina. Se asennetaan seuraavalla käynnistyksellä.',
        'update_stale' => 'Käytössäsi on versio :current — versio :latest on ollut saatavilla 30 päivää. Päivitä nyt.',
        'update_critical' => 'Kriittinen päivitys saatavilla — versio :version korjaa :summary. Asenna mahdollisimman pian.',
        'backup_corrupt_with_path' => 'Kello :timestamp kirjoitettu varmuuskopio ei läpäissyt eheystarkistusta. Tarkista :path. Ratkaise ongelma ennen kuin luotat varmuuskopioihin.',
        'backup_corrupt_no_path' => 'Kello :timestamp yritetty varmuuskopio keskeytyi ennen kuin yhtään tiedostoa syntyi — lähdetietokanta ei läpäissyt eheystarkistusta. Ratkaise ongelma ennen kuin luotat varmuuskopioihin.',

        'backup_overdue' => 'Viimeisin varmennettu varmuuskopio on :hoursh vanha. Suorita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> tai odota kello 03:00 ajoitettua ajoa.',
        'wal_mode_missing' => 'SQLite ei ole WAL-tilassa (nyt :mode). Samanaikaiset kirjoitukset voivat jumittua. Suorita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> saadaksesi ohjeita.',
        'synchronous_misconfigured' => 'SQLiten synchronous-taso on :level (odotettu NORMAL/1). Kirjoitusten kestävyys voi poiketa asetuksista. Suorita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> saadaksesi ohjeita.',
        'oauth_scrub_set_failed' => 'OAuth-salaisuuksien peittäminen ei ole käytössä. Lokit ja auditointiotteet voivat sisältää peittämättömiä valtuustietoja seuraavaan onnistuneeseen lataukseen asti.',
        'oauth_reauth_required' => 'OAuth-salaisuudet siirrettiin käyttäjäkohtaiseen tallennukseen. Valtuuta Gmail ja Microsoft uudelleen, jotta sähköpostien skannaus jatkuu. Vanha salaisuustiedosto nimettiin palautusta varten muotoon :file.',
        'oauth_reconsent' => 'Yhdistä :provider uudelleen',
        'reconnect_link' => 'Yhdistä uudelleen →',
    ],
];
