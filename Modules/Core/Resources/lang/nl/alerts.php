<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systeemmeldingen',

    'actions' => [
        'install_next_launch' => 'Installeren bij volgende start',
        'install_next_launch_aria' => 'Installeren bij volgende start — markeert systeemmelding #:id als opgelost',
        'skip_version' => 'Deze versie overslaan',
        'release_notes' => 'Release-opmerkingen →',
        'update_now' => 'Nu bijwerken',
        'update_now_aria' => 'Nu bijwerken — markeert systeemmelding #:id als opgelost',
        'remind_later' => 'Later herinneren',
        'mark_resolved' => 'Markeren als opgelost',
        'mark_resolved_aria' => 'Markeren als opgelost — systeemmelding #:id',
    ],

    'messages' => [
        'update_available' => 'Update beschikbaar — Beatrax :version staat klaar. Deze wordt bij de volgende start geïnstalleerd.',
        'update_stale' => 'Je gebruikt versie :current — versie :latest is al 30 dagen beschikbaar. Werk nu bij.',
        'update_critical' => 'Kritieke update beschikbaar — versie :version verhelpt :summary. Installeer zo snel mogelijk.',
        'backup_corrupt_with_path' => 'De back-up gemaakt op :timestamp is niet door de integriteitscontrole gekomen. Bekijk :path. Los dit op voordat je op back-ups vertrouwt.',
        'backup_corrupt_no_path' => 'De back-up geprobeerd op :timestamp is afgebroken voordat er een bestand werd geproduceerd — de bron-DB is niet door de integriteitscontrole gekomen. Los dit op voordat je op back-ups vertrouwt.',
        'backup_overdue' => 'De meest recente geverifieerde back-up is :hoursh oud. Voer <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> uit of wacht op de geplande run om 03:00.',
        'wal_mode_missing' => 'SQLite staat niet in WAL-modus (momenteel :mode). Gelijktijdige schrijfacties kunnen vastlopen. Voer <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> uit voor hulp.',
        'synchronous_misconfigured' => 'Het SQLite synchronous-niveau is :level (verwacht NORMAL/1). De duurzaamheidssemantiek kan afwijken van de configuratie. Voer <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> uit voor hulp.',
        'oauth_scrub_set_failed' => 'Het onleesbaar maken van OAuth-geheimen werkt niet. Logboeken en auditfragmenten kunnen tot de volgende geslaagde lading niet-afgeschermde tokens bevatten.',
        'oauth_reauth_required' => 'OAuth-geheimen zijn verplaatst naar opslag per gebruiker. Autoriseer Gmail en Microsoft opnieuw om het scannen van e-mail te hervatten. Het oude geheimenbestand is hernoemd naar :file zodat je kunt terugdraaien.',
        'oauth_reconsent' => 'Koppel je :provider opnieuw',
        'auth_recovery_code_consumed' => 'Herstelcode gebruikt door :username.',
        'auth_recovery_code_failed' => 'Mislukte poging met een herstelcode voor :username.',
        'auth_lock_hard_cap_reached' => 'Afgemeld na te veel mislukte pincodepogingen.',
        'open_banking_reconsent' => 'Koppel je bank opnieuw',
        'auth_lock_corrupted_key' => 'Je pincode kan de appvergrendeling op dit apparaat niet openen: de opgeslagen sleutel is onleesbaar. Meld je aan met je accountwachtwoord om een nieuwe pincode in te stellen.',
        'sync_gdk_rewrap_failed' => 'Het opnieuw inpakken van de GDK-sleutelbos is mislukt na een wijziging van de wachtwoordzin van de appvergrendeling — versleutelde gegevens zijn mogelijk onherstelbaar totdat de sleutelbos opnieuw is ingepakt.',
        'worker_crashed' => 'De achtergrondverwerking van Beatrax is onverwacht gestopt. Imports en e-mailscans staan stil. Open de app opnieuw om die te herstarten.',
        'auth_lock_key_material_stranded' => 'Versleuteling in rust is actief voor dit account, maar geen enkele wikkel van de appvergrendeling houdt de gegevenssleutel nog vast, dus elke versleutelde notitie, omschrijving en tegenpartijgegevens worden als leeg gelezen. Koppelen met een apparaat dat de sleutel nog heeft, is de enige weg terug.',
        'auth_lock_recovery_wrap_stale' => 'Het accountwachtwoord is gewijzigd zonder dat de herstelwikkel van de appvergrendeling opnieuw is ingepakt, dus dat wachtwoord opent de appvergrendeling niet meer. De pincode nog wel. Koppel het accountwachtwoord opnieuw via de instellingen van de appvergrendeling zolang de pincode nog bekend is — anders laat een vergeten pincode niets achter.',
        'reconnect_link' => 'Opnieuw koppelen →',
    ],
];
