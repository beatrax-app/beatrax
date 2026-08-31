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
        'backup_write_failed' => 'Kello :timestamp aloitettu varmuuskopio ei valmistunut — tietokanta läpäisi tarkistuksensa, mutta varmuuskopion tiedostoja ei voitu kirjoittaa. Tarkista vapaa tila ja varmuuskopiokansion oikeudet.',
        'backup_restore_failed' => 'Kello :timestamp aloitettu palautus ei valmistunut. Aiemmat tietosi tallennettiin ensin tiedostoon :snapshot.',

        'backup_overdue' => 'Viimeisin varmennettu varmuuskopio on :hoursh vanha. Beatrax tekee tämän varmuuskopion itse, kerran päivässä, kun sovellus on auki — käsin ei ole mitään suoritettavaa. Jos se pysyy näin vanhana, sovellus ei ole ollut auki päivittäisen ajon kohdalla.',
        'backup_none_found' => 'Varmuuskopiokansiosta ei löytynyt yhtään varmennettua varmuuskopiota. Beatrax tekee tämän varmuuskopion itse, kerran päivässä, kun sovellus on auki — käsin ei ole mitään suoritettavaa.',
        'wal_mode_missing' => 'SQLite ei ole WAL-tilassa (nyt :mode). Samanaikaiset kirjoitukset voivat jumittua. Suorita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> saadaksesi ohjeita.',
        'synchronous_misconfigured' => 'SQLiten synchronous-taso on :level (odotettu NORMAL/1). Kirjoitusten kestävyys voi poiketa asetuksista. Suorita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> saadaksesi ohjeita.',
        'oauth_scrub_set_failed' => 'OAuth-salaisuuksien peittäminen ei ole käytössä. Lokit ja auditointiotteet voivat sisältää peittämättömiä valtuustietoja seuraavaan onnistuneeseen lataukseen asti.',
        'oauth_reauth_required' => 'OAuth-salaisuudet siirrettiin käyttäjäkohtaiseen tallennukseen. Valtuuta Gmail ja Microsoft uudelleen, jotta sähköpostien skannaus jatkuu. Vanha salaisuustiedosto nimettiin palautusta varten muotoon :file.',
        'oauth_reconsent' => 'Yhdistä :provider uudelleen',
        'auth_recovery_code_consumed' => 'Palautuskoodin käytti :username.',
        'auth_recovery_code_failed' => 'Epäonnistunut palautuskoodiyritys käyttäjälle :username.',
        'auth_lock_hard_cap_reached' => 'Uloskirjautuminen liian monen epäonnistuneen PIN-yrityksen jälkeen.',
        'open_banking_reconsent' => 'Yhdistä pankkisi uudelleen',
        'open_banking_nothing_imported' => 'Pankkisi lähetti tapahtumia, mutta Beatrax ei voinut kirjata yhtäkään, joten kirjanpitoosi ei päätynyt mitään. Avaa Pankkiyhteys-asetukset nähdäksesi miksi.',
        'auth_lock_corrupted_key' => 'PIN-koodisi ei avaa sovelluslukitusta tällä laitteella: tallennettu avain ei ole luettavissa. Kirjaudu sisään tilisi salasanalla ja aseta uusi PIN-koodi.',
        'sync_gdk_rewrap_failed' => 'GDK-avainnipun uudelleenkäärintä epäonnistui sovelluslukituksen tunnuslauseen vaihdon jälkeen — salatut tiedot voivat olla palautuskelvottomia, kunnes avainnippu on kääritty uudelleen.',
        'worker_crashed' => 'Beatraxin taustakäsittely pysähtyi odottamatta. Tuonnit ja sähköpostien skannaus ovat tauolla. Käynnistä se uudelleen avaamalla sovellus uudestaan.',
        'auth_lock_key_material_stranded' => 'Levossa oleva salaus on käytössä tällä tilillä, mutta mikään sovelluslukituksen kääre ei enää pidä datan avainta, joten jokainen salattu muistiinpano, kuvaus ja vastapuolitieto luetaan tyhjänä. Ainoa paluutie on laitepari, jolla avain vielä on.',
        'auth_lock_recovery_wrap_stale' => 'Tilin salasana vaihtui ilman, että sovelluslukituksen palautuskääre käärittiin uudelleen, joten kyseinen salasana ei enää avaa sovelluslukitusta. PIN-koodi avaa yhä. Liitä tilin salasana uudelleen sovelluslukituksen asetuksista, kun PIN-koodi on vielä tiedossa — muuten unohtuneen PIN-koodin taakse ei jää mitään.',
        'reconnect_link' => 'Yhdistä uudelleen →',
    ],
];
