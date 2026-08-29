<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemska upozorenja',

    'actions' => [
        'install_next_launch' => 'Instaliraj pri sledećem pokretanju',
        'install_next_launch_aria' => 'Instaliraj pri sledećem pokretanju — označava sistemsko upozorenje br. :id kao rešeno',
        'skip_version' => 'Preskoči ovu verziju',
        'release_notes' => 'Beleške uz izdanje →',
        'update_now' => 'Ažuriraj sada',
        'update_now_aria' => 'Ažuriraj sada — označava sistemsko upozorenje br. :id kao rešeno',
        'remind_later' => 'Podseti me kasnije',
        'mark_resolved' => 'Označi kao rešeno',
        'mark_resolved_aria' => 'Označi kao rešeno — sistemsko upozorenje br. :id',
    ],

    'messages' => [
        'update_available' => 'Dostupno je ažuriranje — Beatrax :version je spreman. Instaliraće se pri sledećem pokretanju.',
        'update_stale' => 'Koristiš verziju :current — verzija :latest je dostupna već 30 dana. Ažuriraj sada.',
        'update_critical' => 'Dostupno je kritično ažuriranje — verzija :version ispravlja :summary. Instaliraj je što pre.',
        'backup_corrupt_with_path' => 'Rezervna kopija zapisana u :timestamp nije prošla proveru integriteta. Pregledaj :path. Reši to pre nego što se osloniš na rezervne kopije.',
        'backup_corrupt_no_path' => 'Rezervna kopija pokrenuta u :timestamp prekinuta je pre nego što je nastala ijedna datoteka — izvorna baza nije prošla proveru integriteta. Reši to pre nego što se osloniš na rezervne kopije.',

        'backup_overdue' => 'Najnovija proverena rezervna kopija stara je :hoursh. Pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ili sačekaj zakazano pokretanje u 03:00.',
        'wal_mode_missing' => 'SQLite nije u WAL režimu (trenutno :mode). Istovremeni upisi mogu da zastanu. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite nivo synchronous je :level (očekuje se NORMAL/1). Ponašanje trajnosti može da se razlikuje od konfiguracije. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'Прикривање OAuth тајни не ради. Записи и изводи ревизије могу садржати неприкривене токене до следећег успешног учитавања.',
        'oauth_reauth_required' => 'OAuth тајне су премештене у складиште по кориснику. Поново ауторизујте Gmail и Microsoft да би се наставило скенирање е-поште. Стара датотека са тајнама преименована је у :file ради враћања.',
        'oauth_reconsent' => 'Поново повежите свој :provider',
        'auth_recovery_code_consumed' => 'Кôд за опоравак употребио је :username.',
        'auth_recovery_code_failed' => 'Неуспео покушај кôда за опоравак за :username.',
        'auth_lock_hard_cap_reached' => 'Одјава након превише неуспелих покушаја ПИН-а.',
        'open_banking_reconsent' => 'Поново повежите своју банку',
        'auth_lock_corrupted_key' => 'Ваш ПИН не може да откључа апликацију на овом уређају: сачувани кључ је нечитљив. Пријавите се лозинком налога да бисте поставили нови ПИН.',
        'sync_gdk_rewrap_failed' => 'Поновно паковање GDK привеска кључева није успело након промене приступне фразе закључавања апликације — шифровани подаци можда неће моћи да се врате док се привезак поново не спакује.',
        'worker_crashed' => 'Beatrax обрада у позадини неочекивано је стала. Увози и скенирања е-поште су паузирани. Поново отворите апликацију да бисте је покренули.',
        'auth_lock_key_material_stranded' => 'Шифровање у мировању активно је за овај налог, али ниједан омотач закључавања апликације више не држи кључ података, па се свака шифрована белешка, опис и податак о другој страни читају као празни. Упаривање са уређајем који још држи кључ једини је пут назад.',
        'auth_lock_recovery_wrap_stale' => 'Лозинка налога промењена је без поновног паковања омотача за опоравак закључавања апликације, па та лозинка више не откључава апликацију. ПИН и даље откључава. Поново повежите лозинку налога у подешавањима закључавања док је ПИН још познат — иначе заборављени ПИН не оставља ништа иза себе.',
        'reconnect_link' => 'Poveži ponovo →',
    ],
];
