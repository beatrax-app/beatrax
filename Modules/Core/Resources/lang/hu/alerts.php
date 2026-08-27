<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Rendszerüzenetek',

    'actions' => [
        'install_next_launch' => 'Telepítés a következő indításkor',
        'install_next_launch_aria' => 'Telepítés a következő indításkor — a #:id rendszerüzenetet megoldottnak jelöli',
        'skip_version' => 'Verzió kihagyása',
        'release_notes' => 'Kiadási megjegyzések →',
        'update_now' => 'Frissítés most',
        'update_now_aria' => 'Frissítés most — a #:id rendszerüzenetet megoldottnak jelöli',
        'remind_later' => 'Emlékeztess később',
        'mark_resolved' => 'Megjelölés megoldottként',
        'mark_resolved_aria' => 'Megjelölés megoldottként — #:id rendszerüzenet',
    ],

    'messages' => [
        'update_available' => 'Elérhető frissítés — a Beatrax :version készen áll. A következő indításkor települ.',
        'update_stale' => 'A(z) :current verziót használod — a(z) :latest verzió 30 napja elérhető. Frissíts most.',
        'update_critical' => 'Kritikus frissítés érhető el — a(z) :version verzió javítja ezt: :summary. Telepítsd mielőbb.',
        'backup_corrupt_with_path' => 'A(z) :timestamp időpontban írt biztonsági mentés megbukott az integritás-ellenőrzésen. Vizsgáld meg ezt: :path. Oldd meg, mielőtt a mentésekre támaszkodnál.',
        'backup_corrupt_no_path' => 'A(z) :timestamp időpontban megkísérelt biztonsági mentés megszakadt, mielőtt bármilyen fájl elkészült volna — a forrásadatbázis megbukott az integritás-ellenőrzésen. Oldd meg, mielőtt a mentésekre támaszkodnál.',

        'backup_overdue' => 'A legutóbbi ellenőrzött biztonsági mentés :hoursh régi. Futtasd a <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> parancsot, vagy várd meg a 03:00-ra ütemezett futást.',
        'wal_mode_missing' => 'Az SQLite nem WAL módban fut (jelenleg: :mode). Az egyidejű írások megakadhatnak. Útmutatásért futtasd a <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> parancsot.',
        'synchronous_misconfigured' => 'Az SQLite synchronous szintje :level (elvárt: NORMAL/1). A tartósság viselkedése eltérhet a beállítottól. Útmutatásért futtasd a <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> parancsot.',
        'oauth_scrub_set_failed' => 'Az OAuth-titkok kitakarása nem működik. A naplók és az auditrészletek a következő sikeres betöltésig kitakaratlan tokeneket tartalmazhatnak.',
        'oauth_reauth_required' => 'Az OAuth-titkok felhasználónkénti tárolóba kerültek. Engedélyezze újra a Gmailt és a Microsoftot az e-mailek vizsgálatának folytatásához. A régi titokfájl visszaállítás céljából :file névre lett átnevezve.',
        'oauth_reconsent' => 'Csatlakoztassa újra a(z) :provider fiókját',
        'reconnect_link' => 'Újracsatlakozás →',
    ],
];
