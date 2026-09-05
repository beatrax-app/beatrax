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
        'assign_in_budgets' => 'Rasporedi u Budžetima',
        'dismiss' => 'Odbaci',
        'dismiss_aria' => 'Odbaci — sistemsko upozorenje br. :id',
    ],

    'messages' => [
        'update_available' => 'Dostupno je ažuriranje — Beatrax :version je spreman. Instaliraće se pri sledećem pokretanju.',
        'update_stale' => 'Koristiš verziju :current — verzija :latest je dostupna već 30 dana. Ažuriraj sada.',
        'update_critical' => 'Dostupno je kritično ažuriranje — verzija :version ispravlja :summary. Instaliraj je što pre.',
        'backup_corrupt_with_path' => 'Rezervna kopija zapisana u :timestamp nije prošla proveru integriteta. Pregledaj :path. Reši to pre nego što se osloniš na rezervne kopije.',
        'backup_corrupt_no_path' => 'Rezervna kopija pokrenuta u :timestamp prekinuta je pre nego što je nastala ijedna datoteka — izvorna baza nije prošla proveru integriteta. Reši to pre nego što se osloniš na rezervne kopije.',
        'backup_write_failed' => 'Rezervna kopija započeta u :timestamp nije dovršena — baza podataka je prošla provere, ali datoteke kopije nisu mogle da se upišu. Proveri slobodan prostor i dozvole fascikle sa kopijama.',
        'backup_restore_failed' => 'Vraćanje započeto u :timestamp nije dovršeno. Tvoji prethodni podaci su prethodno sačuvani u :snapshot.',

        'backup_overdue' => 'Najnovija proverena rezervna kopija stara je :hoursh. Beatrax ovu kopiju pravi sam, jednom dnevno, dok je aplikacija otvorena — ručno nema šta da se pokreće. Ako ostane ovoliko stara, aplikacija nije bila otvorena kad je došlo dnevno pokretanje.',
        'backup_none_found' => 'U fascikli sa rezervnim kopijama nije pronađena nijedna proverena kopija. Beatrax ovu kopiju pravi sam, jednom dnevno, dok je aplikacija otvorena — ručno nema šta da se pokreće.',
        'wal_mode_missing' => 'SQLite nije u WAL režimu (trenutno :mode). Istovremeni upisi mogu da zastanu. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite nivo synchronous je :level (očekuje se NORMAL/1). Ponašanje trajnosti može da se razlikuje od konfiguracije. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'Prikrivanje OAuth tajni ne radi. Zapisi i izvodi revizije mogu sadržati neprikrivene tokene do sledećeg uspešnog učitavanja.',
        'oauth_reauth_required' => 'OAuth tajne su premeštene u skladište po korisniku. Ponovo autorizujte Gmail i Microsoft da bi se nastavilo skeniranje e-pošte. Stara datoteka sa tajnama preimenovana je u :file radi vraćanja.',
        'oauth_reconsent' => 'Ponovo povežite svoj :provider',
        'auth_recovery_code_consumed' => 'Kôd za oporavak upotrebio je :username.',
        'auth_recovery_code_failed' => 'Neuspeo pokušaj kôda za oporavak za :username.',
        'auth_lock_hard_cap_reached' => 'Odjava nakon previše neuspelih pokušaja PIN-a.',
        'open_banking_reconsent' => 'Ponovo povežite svoju banku',
        'open_banking_nothing_imported' => 'Tvoja banka je poslala transakcije, ali Beatrax nije mogao da zabeleži nijednu, pa u tvoju evidenciju nije stiglo ništa. Otvori podešavanja Otvorenog bankarstva da vidiš zašto.',
        'auth_lock_corrupted_key' => 'Vaš PIN ne može da otključa aplikaciju na ovom uređaju: sačuvani ključ je nečitljiv. Prijavite se lozinkom naloga da biste postavili novi PIN.',
        'sync_gdk_rewrap_failed' => 'Ponovno pakovanje GDK priveska ključeva nije uspelo nakon promene pristupne fraze zaključavanja aplikacije — šifrovani podaci možda neće moći da se vrate dok se privezak ponovo ne spakuje.',
        'worker_crashed' => 'Beatrax obrada u pozadini neočekivano je stala. Uvozi i skeniranja e-pošte su pauzirani. Ponovo otvorite aplikaciju da biste je pokrenuli.',
        'auth_lock_key_material_stranded' => 'Šifrovanje u mirovanju aktivno je za ovaj nalog, ali nijedan omotač zaključavanja aplikacije više ne drži ključ podataka, pa se svaka šifrovana beleška, opis i podatak o drugoj strani čitaju kao prazni. Uparivanje sa uređajem koji još drži ključ jedini je put nazad.',
        'auth_lock_recovery_wrap_stale' => 'Lozinka naloga promenjena je bez ponovnog pakovanja omotača za oporavak zaključavanja aplikacije, pa ta lozinka više ne otključava aplikaciju. PIN i dalje otključava. Ponovo povežite lozinku naloga u podešavanjima zaključavanja dok je PIN još poznat — inače zaboravljeni PIN ne ostavlja ništa iza sebe.',
        'reconnect_link' => 'Poveži ponovo →',
        'pots_category_link_retired' => 'Budžetiranje po kovertama zamenilo je kasice povezane sa kategorijom. Iznos :amount iz :count arhivirane kasice ponovo je neraspoređen i čeka da ga rasporediš.|Budžetiranje po kovertama zamenilo je kasice povezane sa kategorijom. Iznos :amount iz :count arhivirane kasice ponovo je neraspoređen i čeka da ga rasporediš.|Budžetiranje po kovertama zamenilo je kasice povezane sa kategorijom. Iznos :amount iz :count arhiviranih kasica ponovo je neraspoređen i čeka da ga rasporediš.',
    ],
];
