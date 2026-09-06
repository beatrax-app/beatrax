<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Afișare',
        'money' => 'Bani',
        'insights' => 'Analize și alerte',
        'security' => 'Securitate și dispozitive',
        'data' => 'Importuri și date',
        'app' => 'Aplicație',
    ],

    'title' => 'Setări',
    'subtitle' => 'Preferințe pentru felul în care îți apar finanțele în aplicație.',

    'appearance' => [
        'heading' => 'Aspect',
        'theme' => 'Temă',
        'theme_light' => 'Luminoasă',
        'theme_dark' => 'Întunecată',
        'theme_system' => 'Sistem',
        'theme_help' => 'Sistem urmează setarea luminoasă sau întunecată a sistemului tău de operare.',
    ],

    'language' => [
        'apply' => 'Aplică',
        'heading' => 'Limbă',
        'label' => 'Limba de afișare',

        'system' => 'Sistem',
        'help' => 'Schimbă cuvintele de pe ecran și modul în care sunt scrise sumele. Sistem urmează limba browserului sau a sistemului de operare, cu engleza ca variantă implicită.',
    ],

    'sample_data' => [
        'heading' => 'Date de exemplu',
        'help' => 'Umple acest cont cu un registru inventat — conturi, tranzacții, bugete, obiective și alerte — ca să ai ce privi. Se adaugă la ce există deja și nimic din el nu sunt datele unei persoane reale.',
        'warning' => 'Asta scrie în propriul tău registru și ajunge pe dispozitivele împerecheate. De pe acest ecran nu se poate anula.',
        'confirm' => 'Adaugă-l la acest cont',
        'cancel' => 'Anulează',
        'load' => 'Încarcă date de exemplu',
        'working' => 'Se construiește registrul de exemplu. Durează un moment.',
        'loaded' => 'Date de exemplu adăugate (:count).',
    ],

    'country' => [
        'heading' => 'Țară',
        'label' => 'Țara ta',
        'help' => 'Stabilește după ce țară recunoaște aplicația regulile fiscale, instituțiile publice și comisioanele bancare. Nu schimbă limba și nici modul în care sunt scrise sumele.',
        'choose' => 'Alege o țară…',
        'switch_note' => 'Schimbarea adaugă categorii noi — etichetele existente nu se modifică niciodată.',

        'wording_note' => 'Numele categoriilor fiscale apar în limba ta; declarația fiscală din :country folosește propriii termeni.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Belgia',
            'bg' => 'Bulgaria',
            'ca' => 'Canada',
            'ch' => 'Elveția',
            'cy' => 'Cipru',
            'cz' => 'Cehia',
            'de' => 'Germania',
            'dk' => 'Danemarca',
            'ee' => 'Estonia',
            'es' => 'Spania',
            'fi' => 'Finlanda',
            'fr' => 'Franța',
            'gb' => 'Regatul Unit',
            'gr' => 'Grecia',
            'hr' => 'Croația',
            'hu' => 'Ungaria',
            'ie' => 'Irlanda',
            'is' => 'Islanda',
            'it' => 'Italia',
            'lt' => 'Lituania',
            'lu' => 'Luxemburg',
            'lv' => 'Letonia',
            'mt' => 'Malta',
            'nl' => 'Țările de Jos',
            'no' => 'Norvegia',
            'pl' => 'Polonia',
            'pt' => 'Portugalia',
            'ro' => 'România',
            'se' => 'Suedia',
            'si' => 'Slovenia',
            'sk' => 'Slovacia',
            'us' => 'Statele Unite',
        ],
    ],

    'currency_display' => [
        'heading' => 'Afișarea sumei',
        'label' => 'Vizualizarea implicită a sumelor',
        'eur_only' => 'Sumă decontată',
        'original' => 'Sumă originală',
        'help' => 'Se aplică listei de tranzacții și totalurilor din tabloul de bord. Poți schimba în continuare pentru fiecare pagină, dar numai din lista de tranzacții.',
    ],

    'base_currency' => [
        'heading' => 'Moneda de raportare de bază',
        'label' => 'Monedă de raportare',
        'help' => 'Toate totalurile și centralizările se convertesc în această monedă. Fiecare cont își arată în continuare și moneda proprie.',
    ],

    'exchange_rates' => [
        'heading' => 'Cursuri valutare',
        'fetch_online' => 'Preia online cursurile actuale',
        'online_on' => 'Cursurile sunt preluate zilnic de la ECB sau de la Frankfurter, dacă ECB nu este disponibil. Doar interogări de perechi valutare — fără date personale.',
        'last_updated' => 'Ultima actualizare: :date.',
        'online_off' => 'Se folosesc în continuare cursurile deja existente, iar instantaneul inclus servește ca rezervă. Niciun fel de date nu părăsesc acest dispozitiv.',
        'fetch_aria' => 'Preia online cursurile valutare actuale',
        'refreshing' => 'Se reîmprospătează…',
        'next_refresh' => 'Reîmprospătare automată: o dată pe zi',
        'refresh_gave_up' => 'Cursurile nu au putut fi reîmprospătate. Se folosesc în continuare cele de pe acest dispozitiv.',
        'refresh_now' => 'Reîmprospătează acum',
    ],

    'period' => [
        'heading' => 'Perioadă',
        'label' => 'Perioada începe în ziua',
        'help' => 'Numerotate de la 1 la 28. Majoritatea utilizatorilor lasă 1 (luna calendaristică). Alege 25 dacă salariul îți intră pe 25 și te gândești la „luna ta” ca începând atunci.',

        'move_confirm' => 'Dacă perioada începe în ziua :day, toate sumele din plicuri sunt reorganizate și adunate acolo unde două luni se contopesc într-una. Revenirea la ziua anterioară nu le mai separă.',
        'move_cancel' => 'Anulează',
        'move_apply' => 'Aplică',
    ],

    'recurring' => [
        'heading' => 'Detectarea plăților recurente',
        'window_label' => 'Fereastră de detectare (luni)',
        'window_help' => 'Câte luni de istoric să fie scanate la gruparea tranzacțiilor în tipare recurente.',
        'income_label' => 'Venit minim (unități minore)',
        'income_help' => 'Veniturile sub acest prag nu sunt grupate automat. Stocat în unități minore — :minor înseamnă :example. Setează 0 ca să dezactivezi pragul.',
    ],

    'drift' => [
        'heading' => 'Alerte de abatere',
        'label' => 'Pragul implicit pentru alertele de abatere',
        'help' => 'Alertele se declanșează când suma cea mai recentă a unei plăți recurente diferă de cea anterioară cu mai mult decât acest procent. Setările per serie au prioritate.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (implicit)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Salvează setările',
    'saved' => 'Salvat.',

    'anomaly_heading' => 'Detectarea anomaliilor',
    'notifications_heading' => 'Notificări',

    'forecasting' => [
        'heading' => 'Previziuni',
        'intro' => 'Beatrax îți proiectează soldul în viitor pornind de la starea actuală a conturilor. Pentru conturile fără solduri din extrase (PayPal, importuri CSV vechi), setează aici soldul inițial ca proiecțiile să pornească dintr-un punct cunoscut.',
        'no_accounts' => 'Încă niciun cont — importă un extras de cont ca să adaugi unul.',
    ],

    'auto_import' => [
        'heading' => 'Import automat',
        'label' => 'Import automat din folderul de depunere',

        'active_html' => 'Folderul de depunere este activ. Beatrax scanează <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> la fiecare 5 minute după fișiere noi.',
        'inactive_html' => 'Când este pornit, Beatrax scanează <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> la fiecare 5 minute după fișiere <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> și <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> și le importă prin același flux de potrivire ca asistentul. Fișierele procesate se mută în <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, ca să nu fie importate niciodată de două ori.',
        'active_phone_html' => 'Folderul de depunere este activ. Beatrax scanează <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> în fundal după fișiere noi. Telefonul tău decide când rulează o scanare în fundal, așa că pot trece minute sau ore.',
        'inactive_phone_html' => 'Când este pornit, Beatrax scanează <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> în fundal după fișiere <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> și <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> și le importă prin același flux de potrivire ca asistentul. Telefonul tău decide când rulează o scanare în fundal, așa că pot trece minute sau ore. Fișierele procesate se mută în <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, ca să nu fie importate niciodată de două ori.',
    ],

    'aliases' => [
        'heading' => 'Aliasuri',
        'intro' => 'Revizuiește și editează numele prietenoase pe care l-ai învățat pe Beatrax pentru descrierile criptice din extrase.',
        'manage' => 'Gestionează aliasurile →',
    ],

    'tax_heading' => 'Impozite',
    'data_backup_heading' => 'Date și copii de rezervă',

    'about_updates' => [
        'heading' => 'Despre actualizări',
        'body' => 'Beatrax se actualizează singur odată instalat. După ce instalezi prima versiune, versiunile viitoare ajung printr-un banner în aplicație — nu mai trebuie să revii pe GitHub. Dacă vreodată o actualizare nu se aplică, poți oricând să descarci manual cel mai recent installer de pe pagina de versiuni.',
        'body_phone' => 'Aici Beatrax nu se actualizează singur. Versiunile noi ale aplicației de telefon ajung prin App Store sau Google Play, la fel ca celelalte aplicații ale tale.',
        'check_label' => 'Verifică automat actualizările',
        'check_on' => 'Beatrax întreabă fluxul de versiuni dacă există o versiune semnată mai nouă. Nu se descarcă nimic până când nu alegi tu să o instalezi.',
        'check_off' => 'Nu se face nicio verificare a actualizărilor și nimic nu părăsește acest dispozitiv. Versiunile noi le găsești deschizând singur pagina de versiuni.',
        'open_releases' => 'Deschide pagina de versiuni →',
    ],

    'privacy' => [
        'heading' => 'Politica de confidențialitate',
        'body' => 'Beatrax îți ține finanțele pe propriile tale dispozitive. Politica explică ce înseamnă asta, ce trimit funcțiile online opționale și cum îți poți elimina datele.',
        'open' => 'Citește politica de confidențialitate →',
        'url_hint' => 'Dacă linkul nu se deschide, intră pe:',
    ],

    'first_run_tour' => [
        'heading' => 'Tur la prima pornire',
        'body' => 'Repornește asistentul de configurare dacă vrei să parcurgi din nou fluxul introductiv.',
        'run_again' => 'Rulează din nou asistentul de configurare',
    ],

    'developer' => [
        'heading' => 'Dezvoltator',
        'label' => 'Consolă de dezvoltare în aplicație',
        'help' => 'Afișează consola de dezvoltare la /dev. Resetează comutatorul Avansat la fiecare autentificare.',
        'aria' => 'Mod dezvoltator',
    ],

    'errors' => [
        'period_move_failed' => 'Luna de buget nu a putut fi mutată, așa că a rămas unde era.',
        'currency_required' => 'Alege o monedă.',
        'window_months' => 'Alege între 2 și 60 de luni.',
        'threshold' => 'Alege un prag dintre 1 %, 2 %, 5 %, 10 %, 25 % sau 50 %.',
        'amount' => 'Introdu o sumă de la :zero în sus.',
        'period_day' => 'Alege o zi de la 1 la 28.',
        'currency_view' => 'Alege una dintre opțiunile disponibile.',
    ],
];
