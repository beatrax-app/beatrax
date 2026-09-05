<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Süsteemi hoiatused',

    'actions' => [
        'install_next_launch' => 'Paigalda järgmisel käivitamisel',
        'install_next_launch_aria' => 'Paigalda järgmisel käivitamisel — märgib süsteemi hoiatuse #:id lahendatuks',
        'skip_version' => 'Jäta see versioon vahele',
        'release_notes' => 'Väljalaske märkmed →',
        'update_now' => 'Uuenda kohe',
        'update_now_aria' => 'Uuenda kohe — märgib süsteemi hoiatuse #:id lahendatuks',
        'remind_later' => 'Tuleta hiljem meelde',
        'mark_resolved' => 'Märgi lahendatuks',
        'mark_resolved_aria' => 'Märgi lahendatuks — süsteemi hoiatus #:id',
        'assign_in_budgets' => 'Jaga Eelarvetes',
        'dismiss' => 'Peida',
        'dismiss_aria' => 'Peida — süsteemi hoiatus #:id',
    ],

    'messages' => [
        'update_available' => 'Uuendus on saadaval — Beatrax :version on valmis. See paigaldatakse järgmisel käivitamisel.',
        'update_stale' => 'Kasutad versiooni :current — versioon :latest on olnud saadaval 30 päeva. Uuenda kohe.',
        'update_critical' => 'Saadaval on kriitiline uuendus — versioon :version parandab: :summary. Paigalda esimesel võimalusel.',
        'backup_corrupt_with_path' => 'Varukoopia, mis kirjutati :timestamp, ei läbinud terviklikkuse kontrolli. Kontrolli asukohta :path. Lahenda see enne, kui varukoopiatele toetud.',
        'backup_corrupt_no_path' => 'Varukoopia, mida üritati teha :timestamp, katkes enne ühegi faili loomist — lähteandmebaas ei läbinud terviklikkuse kontrolli. Lahenda see enne, kui varukoopiatele toetud.',
        'backup_write_failed' => ':timestamp alustatud varukoopia jäi lõpetamata — andmebaas läbis kontrollid, kuid varukoopia faile ei õnnestunud kirjutada. Kontrolli vaba ruumi ja varukoopiate kausta õigusi.',
        'backup_restore_failed' => ':timestamp alustatud taastamine jäi lõpetamata. Sinu varasemad andmed salvestati enne seda faili :snapshot.',

        'backup_overdue' => 'Viimane kontrollitud varukoopia on :hoursh vana. Beatrax teeb selle koopia ise, kord päevas, sel ajal kui rakendus on avatud — käsitsi pole midagi käivitada. Kui see jääb nii vanaks, ei olnud rakendus avatud siis, kui igapäevane käivitus kätte jõudis.',
        'backup_none_found' => 'Varukoopiate kaustast ei leitud ühtki kontrollitud varukoopiat. Beatrax teeb selle koopia ise, kord päevas, sel ajal kui rakendus on avatud — käsitsi pole midagi käivitada.',
        'wal_mode_missing' => 'SQLite ei ole WAL-režiimis (praegu :mode). Samaaegsed kirjutamised võivad takerduda. Juhiste saamiseks käivita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite synchronous-tase on :level (oodatud NORMAL/1). Andmete püsivus võib konfiguratsioonist erineda. Juhiste saamiseks käivita <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'OAuth-saladuste varjamine ei tööta. Logid ja auditi väljavõtted võivad kuni järgmise õnnestunud laadimiseni sisaldada varjamata lubasid.',
        'oauth_reauth_required' => 'OAuth-saladused viidi kasutajapõhisesse hoidlasse. Autoriseeri Gmail ja Microsoft uuesti, et e-kirjade skannimine jätkuks. Vana saladuste fail nimetati tagasipööramiseks ümber failiks :file.',
        'oauth_reconsent' => 'Ühenda oma :provider uuesti',
        'auth_recovery_code_consumed' => 'Taastekoodi kasutas :username.',
        'auth_recovery_code_failed' => 'Nurjunud taastekoodi katse kasutajale :username.',
        'auth_lock_hard_cap_reached' => 'Välja logitud pärast liiga paljusid nurjunud PIN-koodi katseid.',
        'open_banking_reconsent' => 'Ühenda oma pank uuesti',
        'open_banking_nothing_imported' => 'Sinu pank saatis tehinguid, kuid Beatrax ei suutnud ühtegi neist kirjendada, seega ei jõudnud sinu arvestusse midagi. Ava Pangaliidese seaded, et näha miks.',
        'auth_lock_corrupted_key' => 'Sinu PIN-kood ei ava selles seadmes rakenduse lukku: salvestatud võti ei ole loetav. Logi sisse konto parooliga, et määrata uus PIN-kood.',
        'sync_gdk_rewrap_failed' => 'GDK võtmehoidja ümberpakkimine ebaõnnestus pärast rakenduseluku paroolifraasi muutmist — krüpteeritud andmed võivad olla taastamatud, kuni võtmehoidja on uuesti pakitud.',
        'worker_crashed' => 'Beatraxi taustatöötlus peatus ootamatult. Importimine ja e-kirjade skannimine on peatatud. Taaskäivitamiseks ava rakendus uuesti.',
        'auth_lock_key_material_stranded' => 'Selle konto puhul on puhkeoleku krüptimine aktiivne, kuid ükski rakenduseluku ümbris ei hoia enam andmevõtit, seega loetakse iga krüpteeritud märkus, kirjeldus ja vastaspoole detail tühjaks. Ainus tee tagasi on siduda seade, mis võtit veel hoiab.',
        'auth_lock_recovery_wrap_stale' => 'Konto parool muutus ilma, et rakenduseluku taasteümbris oleks uuesti pakitud, seega see parool enam rakenduse lukku ei ava. PIN-kood avab endiselt. Seo konto parool rakenduseluku seadetes uuesti, kuni PIN-kood on veel teada — muidu ei jää unustatud PIN-koodi taha midagi.',
        'reconnect_link' => 'Ühenda uuesti →',
        'pots_category_link_retired' => 'Ümbrikutega eelarvestamine on asendanud kategooriaga seotud kogumispotid. :count arhiveeritud potist vabanenud :amount on taas jaotamata ja ootab, et sa selle ära jagaksid.|Ümbrikutega eelarvestamine on asendanud kategooriaga seotud kogumispotid. :count arhiveeritud potist vabanenud :amount on taas jaotamata ja ootab, et sa selle ära jagaksid.',
    ],
];
