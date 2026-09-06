<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemska opozorila',

    'actions' => [
        'download_and_install' => 'Prenesi in namesti',
        'download_and_install_aria' => 'Prenesi in namesti — sistemsko opozorilo št. :id označi kot rešeno',
        'skip_version' => 'Preskoči to različico',
        'release_notes' => 'Opombe ob izdaji →',
        'update_now' => 'Posodobi zdaj',
        'update_now_aria' => 'Posodobi zdaj — sistemsko opozorilo št. :id označi kot rešeno',
        'remind_later' => 'Opomni me pozneje',
        'mark_resolved' => 'Označi kot rešeno',
        'mark_resolved_aria' => 'Označi kot rešeno — sistemsko opozorilo št. :id',
        'assign_in_budgets' => 'Razporedi v Proračunih',
        'dismiss' => 'Opusti',
        'dismiss_aria' => 'Opusti — sistemsko opozorilo št. :id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'proračunska opozorila',
        'daily-triggers' => 'dnevne opomnike in povzetek',
    ],

    'messages' => [
        'update_available' => 'Na voljo je posodobitev — Beatrax :version. Nič se ne prenese, dokler sam ne izbereš namestitve; Beatrax se nato zapre in znova odpre v novi različici.',
        'update_refused' => 'Beatrax je prenesel različico :version in je ni namestil — datoteka se ni ujemala s podpisom izdajatelja, zato se na tej napravi ni nič spremenilo. To lahko povzroči poškodovan prenos. Če se ponavlja, Beatraxa ne nameščaj iz tega vira.',
        'update_stale' => 'Uporabljaš različico :current — različica :latest je na voljo že 30 dni. Posodobi zdaj.',
        'update_critical' => 'Na voljo je kritična posodobitev — različica :version popravlja :summary. Namesti jo čim prej.',
        'backup_corrupt_with_path' => 'Varnostna kopija, zapisana ob :timestamp, ni prestala preverjanja celovitosti. Preglej :path. Reši to, preden se zaneseš na varnostne kopije.',
        'backup_corrupt_no_path' => 'Varnostna kopija, sprožena ob :timestamp, se je prekinila, preden je nastala kakršna koli datoteka — izvorna zbirka podatkov ni prestala preverjanja celovitosti. Reši to, preden se zaneseš na varnostne kopije.',
        'backup_write_failed' => 'Varnostna kopija, začeta ob :timestamp, se ni dokončala — zbirka podatkov je prestala preverjanja, datotek kopije pa ni bilo mogoče zapisati. Preveri prosti prostor in dovoljenja mape z varnostnimi kopijami.',
        'backup_restore_failed' => 'Obnovitev, začeta ob :timestamp, se ni dokončala. Tvoji prejšnji podatki so bili pred tem shranjeni v :snapshot.',

        'backup_overdue' => 'Najnovejša preverjena varnostna kopija je stara :hoursh. Beatrax to kopijo naredi sam, enkrat na dan, dokler je aplikacija odprta — ročno ni ničesar za zagnati. Če ostane tako stara, aplikacija ni bila odprta, ko je prišel dnevni zagon.',
        'backup_none_found' => 'V mapi z varnostnimi kopijami ni bilo najdene nobene preverjene kopije. Beatrax to kopijo naredi sam, enkrat na dan, dokler je aplikacija odprta — ročno ni ničesar za zagnati.',
        'wal_mode_missing' => 'Zbirka podatkov ni v načinu WAL (trenutno :mode), zato se lahko shranjevanje ustavi, dokler se izvaja opravilo v ozadju. Beatrax nastavi WAL ob vsakem zagonu, zato ponovni zagon to običajno odpravi.',
        'synchronous_misconfigured' => 'Raven trajnosti zbirke podatkov je :level namesto pričakovane NORMAL. Beatrax jo nastavi ob vsakem zagonu, zato ponovni zagon to običajno odpravi.',
        'oauth_scrub_set_failed' => 'Prikrivanje skrivnosti OAuth ne deluje. Dnevniki in izvlečki revizije lahko do naslednjega uspešnega nalaganja vsebujejo neprikrite žetone.',
        'oauth_reauth_required' => 'Skrivnosti OAuth so bile premaknjene v shrambo za posameznega uporabnika. Znova avtorizirajte Gmail in Microsoft, da se pregledovanje e-pošte nadaljuje. Stara datoteka s skrivnostmi je bila zaradi vrnitve preimenovana v :file.',
        'oauth_reconsent' => 'Znova povežite svoj :provider',
        'auth_recovery_code_consumed' => 'Kodo za obnovitev je uporabil :username.',
        'auth_recovery_code_failed' => 'Neuspel poskus kode za obnovitev za :username.',
        'auth_lock_hard_cap_reached' => 'Odjava po preveč neuspelih poskusih PIN.',
        'open_banking_reconsent' => 'Znova povežite svojo banko',
        'open_banking_nothing_imported' => 'Tvoja banka je poslala transakcije, a Beatrax ni mogel zabeležiti nobene, zato v tvojo evidenco ni prišlo nič. Odpri nastavitve Odprtega bančništva, da vidiš zakaj.',
        'auth_lock_corrupted_key' => 'Vaš PIN v tej napravi ne more odkleniti aplikacije: shranjeni ključ ni berljiv. Prijavite se z geslom računa in nastavite nov PIN.',
        'sync_gdk_rewrap_failed' => 'Ponovno ovijanje obeska GDK po spremembi geselne fraze zaklepanja aplikacije ni uspelo — šifriranih podatkov morda ne bo mogoče obnoviti, dokler obesek ni ponovno ovit.',
        'worker_crashed' => 'Beatraxova obdelava v ozadju se je nepričakovano ustavila. Uvozi in pregledovanje e-pošte so zaustavljeni. Znova odprite aplikacijo, da jo zaženete.',
        'auth_lock_key_material_stranded' => 'Šifriranje v mirovanju je za ta račun aktivno, vendar noben ovoj zaklepanja aplikacije ne hrani več ključa podatkov, zato se vsaka šifrirana opomba, opis in podatek o nasprotni stranki prebere kot prazna. Obnovite šifrirano varnostno kopijo, narejeno, ko je ključ še deloval, ali ta račun znova nastavite na napravi, ki ga še ima.',
        'auth_lock_recovery_wrap_stale' => 'Geslo računa se je spremenilo, ne da bi bil ovoj za obnovitev zaklepanja aplikacije ponovno ovit, zato to geslo ne odklene več aplikacije. PIN jo še vedno odklene. Znova povežite geslo računa v nastavitvah zaklepanja, dokler je PIN še znan — sicer za pozabljenim PIN-om ne ostane nič.',
        'reconnect_link' => 'Poveži znova →',
        'pots_category_link_retired' => 'Proračun po ovojnicah je nadomestil hranilnike, vezane na kategorijo. Znesek :amount iz :count arhiviranega hranilnika je spet nerazporejen in čaka, da ga razporediš.|Proračun po ovojnicah je nadomestil hranilnike, vezane na kategorijo. Znesek :amount iz :count arhiviranih hranilnikov je spet nerazporejen in čaka, da ga razporediš.|Proračun po ovojnicah je nadomestil hranilnike, vezane na kategorijo. Znesek :amount iz :count arhiviranih hranilnikov je spet nerazporejen in čaka, da ga razporediš.|Proračun po ovojnicah je nadomestil hranilnike, vezane na kategorijo. Znesek :amount iz :count arhiviranih hranilnikov je spet nerazporejen in čaka, da ga razporediš.',
        'notifications_deferred_pass_failed' => 'Beatrax na tej napravi ni mogel izračunati :pass, zato jih nekaj morda manjka. Poskusil bo znova vsakič, ko odprete aplikacijo.',
    ],
];
