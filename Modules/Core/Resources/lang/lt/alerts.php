<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemos įspėjimai',

    'actions' => [
        'download_and_install' => 'Atsisiųsti ir įdiegti',
        'download_and_install_aria' => 'Atsisiųsti ir įdiegti — sistemos įspėjimas #:id pažymimas kaip išspręstas',
        'skip_version' => 'Praleisti šią versiją',
        'release_notes' => 'Versijos aprašas →',
        'update_now' => 'Atnaujinti dabar',
        'update_now_aria' => 'Atnaujinti dabar — sistemos įspėjimas #:id pažymimas kaip išspręstas',
        'remind_later' => 'Priminti vėliau',
        'mark_resolved' => 'Žymėti kaip išspręstą',
        'mark_resolved_aria' => 'Žymėti kaip išspręstą — sistemos įspėjimas #:id',
        'assign_in_budgets' => 'Paskirstyti Biudžetuose',
        'dismiss' => 'Slėpti',
        'dismiss_aria' => 'Slėpti — sistemos įspėjimas #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'biudžeto įspėjimų',
        'daily-triggers' => 'kasdienių priminimų ir suvestinės',
    ],

    'messages' => [
        'update_available' => 'Yra atnaujinimas — Beatrax :version. Nieko neatsisiunčiama, kol nepasirenki įdiegti; tada Beatrax užsidaro ir vėl atsidaro su nauja versija.',
        'update_stale' => 'Naudoji :current versiją — :latest versija prieinama jau 30 dienų. Atnaujink dabar.',
        'update_critical' => 'Yra svarbus atnaujinimas — :version versija ištaiso: :summary. Įdiek kuo greičiau.',
        'backup_corrupt_with_path' => 'Atsarginė kopija, sukurta :timestamp, neišlaikė vientisumo patikros. Patikrink :path. Išspręsk tai, kol nepradėjai pasikliauti atsarginėmis kopijomis.',
        'backup_corrupt_no_path' => 'Atsarginė kopija, bandyta sukurti :timestamp, nutrūko dar nesukūrus jokio failo — šaltinio duomenų bazė neišlaikė vientisumo patikros. Išspręsk tai, kol nepradėjai pasikliauti atsarginėmis kopijomis.',
        'backup_write_failed' => ':timestamp pradėta atsarginė kopija nebuvo baigta — duomenų bazė patikras praėjo, bet kopijos failų įrašyti nepavyko. Patikrink laisvą vietą ir kopijų aplanko teises.',
        'backup_restore_failed' => ':timestamp pradėtas atkūrimas nebuvo baigtas. Ankstesni tavo duomenys prieš tai buvo įrašyti į :snapshot.',

        'backup_overdue' => 'Naujausiai patikrintai atsarginei kopijai jau :hoursh. Beatrax šią kopiją daro pati, kartą per dieną, kol programėlė atidaryta — ranka nieko paleisti nereikia. Jei ji lieka tokia sena, programėlė nebuvo atidaryta, kai atėjo kasdienis paleidimas.',
        'backup_none_found' => 'Atsarginių kopijų aplanke nerasta nė vienos patikrintos kopijos. Beatrax šią kopiją daro pati, kartą per dieną, kol programėlė atidaryta — ranka nieko paleisti nereikia.',
        'wal_mode_missing' => 'Duomenų bazė neveikia WAL režimu (šiuo metu :mode), todėl išsaugojimas gali sustoti, kol vykdoma fono užduotis. Beatrax nustato WAL kiekvieno paleidimo metu, todėl paleidimas iš naujo tai paprastai išsprendžia.',
        'synchronous_misconfigured' => 'Duomenų bazės patvarumo lygis yra :level vietoj laukiamo NORMAL. Beatrax jį nustato kiekvieno paleidimo metu, todėl paleidimas iš naujo tai paprastai išsprendžia.',
        'oauth_scrub_set_failed' => 'OAuth paslapčių slėpimas neveikia. Žurnaluose ir audito ištraukose iki kito sėkmingo įkėlimo gali būti nepaslėptų prieigos raktų.',
        'oauth_reauth_required' => 'OAuth paslaptys perkeltos į atskiro naudotojo saugyklą. Iš naujo autorizuokite „Gmail“ ir „Microsoft“, kad būtų tęsiamas el. laiškų nuskaitymas. Senas paslapčių failas pervadintas į :file, kad būtų galima grįžti atgal.',
        'oauth_reconsent' => 'Iš naujo prijunkite savo :provider',
        'auth_recovery_code_consumed' => 'Atkūrimo kodą panaudojo :username.',
        'auth_recovery_code_failed' => 'Nepavykęs atkūrimo kodo bandymas naudotojui :username.',
        'auth_lock_hard_cap_reached' => 'Atsijungta po per daug nepavykusių PIN bandymų.',
        'open_banking_reconsent' => 'Iš naujo prijunkite savo banką',
        'open_banking_nothing_imported' => 'Jūsų bankas atsiuntė operacijų, bet „Beatrax“ nepavyko įrašyti nė vienos, todėl į jūsų apskaitą nepateko nieko. Atverkite „Atviroji bankininkystė“ nustatymus, kad pamatytumėte kodėl.',
        'auth_lock_corrupted_key' => 'Jūsų PIN kodas negali atrakinti programos šiame įrenginyje: išsaugotas raktas neįskaitomas. Prisijunkite paskyros slaptažodžiu ir nustatykite naują PIN kodą.',
        'sync_gdk_rewrap_failed' => 'Nepavyko iš naujo supakuoti GDK raktinės po programos užrakto slaptafrazės keitimo — užšifruoti duomenys gali būti neatkuriami, kol raktinė nebus supakuota iš naujo.',
        'worker_crashed' => '„Beatrax“ fone vykdomas apdorojimas netikėtai sustojo. Importavimas ir el. laiškų nuskaitymas pristabdyti. Kad paleistumėte iš naujo, vėl atidarykite programą.',
        'auth_lock_key_material_stranded' => 'Šiai paskyrai įjungtas ramybės būsenos šifravimas, tačiau nė vienas programos užrakto apvalkalas nebeturi duomenų rakto, todėl kiekviena užšifruota pastaba, aprašas ir sandorio šalies informacija rodoma tuščia. Atkurkite užšifruotą atsarginę kopiją, sukurtą dar veikiant raktui, arba iš naujo nustatykite šią paskyrą įrenginyje, kuris raktą vis dar turi.',
        'auth_lock_recovery_wrap_stale' => 'Paskyros slaptažodis pasikeitė, o programos užrakto atkūrimo apvalkalas nebuvo supakuotas iš naujo, todėl tas slaptažodis nebeatrakina programos. PIN kodas vis dar atrakina. Iš naujo susiekite paskyros slaptažodį užrakto nustatymuose, kol PIN kodas dar žinomas — kitaip už pamiršto PIN kodo nieko nelieka.',
        'reconnect_link' => 'Prijungti iš naujo →',
        'pots_category_link_retired' => 'Biudžetas vokuose pakeitė su kategorija susietas taupykles. Suma :amount iš :count archyvuotos taupyklės vėl nepaskirstyta ir laukia, kol ją paskirstysite.|Biudžetas vokuose pakeitė su kategorija susietas taupykles. Suma :amount iš :count archyvuotų taupyklių vėl nepaskirstyta ir laukia, kol ją paskirstysite.|Biudžetas vokuose pakeitė su kategorija susietas taupykles. Suma :amount iš :count archyvuotų taupyklių vėl nepaskirstyta ir laukia, kol ją paskirstysite.',
        'notifications_deferred_pass_failed' => '„Beatrax“ šiame įrenginyje negalėjo apskaičiuoti :pass, todėl kai kurių gali trūkti. Bandys dar kartą kaskart atidarius programėlę.',
    ],
];
