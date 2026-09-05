<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-suoritin',
    'subtitle' => 'Suorita SAFE-komennot yhdellä napsautuksella; DESTRUCTIVE-komennot ovat kolminkertaisen portin takana.',
    'run_a_command' => 'Suorita komento',
    'filter_aria' => 'Suoritussuodatin',
    'filter' => [
        'all' => 'Kaikki',
        'running' => 'Käynnissä',
        'failed' => 'Epäonnistuneet',
        'destructive' => 'Tuhoavat',
    ],
    'worker_running' => 'Jonon työntekijä: KÄYNNISSÄ',
    'worker_not_running' => 'Jonon työntekijä: EI KÄYNNISSÄ',
    'no_runs' => 'Ei vielä suorituksia. Napsauta ”Suorita komento” tai käytä komentopalettia (⌘K).',
    // i18n-review: fi · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Ei vielä suorituksia. Napauta ”Suorita komento” tai käytä komentopalettia (⌘K).',
    'recent_runs_aria' => 'Viimeisimmät suoritukset',
    'modal_heading' => 'Suorita SAFE-komento',
    'modal_intro' => 'Valitse SAFE-tason komento suoritettavaksi heti. DESTRUCTIVE-komentoja ei ole listattu tässä — käytä aikajanan Suorita uudelleen -toimintoa tai ⌘K-palettia.',
    'args_badge' => 'args',
    'args_badge_title' => 'Avaa argumenttilomakkeen',

    'spawning_unavailable' => 'Artisan-komennot ajetaan erillisessä prosessissa, eikä tämä alusta anna sovelluksen käynnistää sellaista. Aja ne tietokonesovelluksessa.',

    'status' => [
        'running' => 'Käynnissä',
        'done' => 'Valmis',
        'failed' => 'Epäonnistui',
        'cancelled' => 'Peruutettu',
    ],
    'cancel' => 'Peruuta',
    'rerun' => 'Suorita uudelleen',
    'started' => 'Aloitettu :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Tuntematon komento: :command',
        'missing_args' => 'Komentoa :command ei voi suorittaa — tarvitaan :noun: :list',
        'invalid_args' => 'Komentoa :command ei voi suorittaa — :reason',
        'arg' => 'argumentti|argumentit',
        'started' => 'Aloitettiin :command (suoritus :runId)',
        'run_expired' => 'Suoritustietue vanhentui — uudelleensuoritus ei onnistu.',
        'reran' => 'Suoritettiin uudelleen :command (suoritus :runId)',
        'rerun_forbidden' => 'Tämä suoritus kuuluu toiselle kehittäjälle.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Varmuuskopioi tietokanta', 'description' => 'Kirjoittaa aikaleimatun SQLite-kopion varmuuskopiohakemistoon, ellei tietokanta ole pysynyt muuttumattomana edellisen kopion jälkeen. Talteen jäävä kopio poistaa myös vanhemmat varmuuskopiot säilytyskäytännön mukaan.'],
        'doctor' => ['label' => 'Suorita doctor', 'description' => 'Ajaa operatiivisen testisarjan ja ilmoittaa jokaiselta riviltä pass / warn / fail. Warn- tai fail-rivi tuottaa nollasta poikkeavan paluukoodin.'],
        'failed_jobs' => ['label' => 'Siivoa epäonnistuneet työt', 'description' => 'Poistaa Laravelin hallitsemasta failed_jobs-taulusta jokaisen rivin, joka on yli 30 päivää vanha, riippumatta siitä yritettiinkö työtä koskaan uudelleen.'],
        'cache_clear' => ['label' => 'Tyhjennä välimuisti', 'description' => 'Tyhjentää sovelluksen välimuistin.'],
        'route_list' => ['label' => 'Listaa reitit', 'description' => 'Tulostaa jokaisen rekisteröidyn HTTP-reitin vakiotulosteeseen.'],
        'config_show' => ['label' => 'Näytä asetukset', 'description' => 'Tulostaa kokonaisen asetustiedoston tai sen sisältämän pisteillä erotellun avaimen arvon.'],
        'view_clear' => ['label' => 'Tyhjennä näkymävälimuisti', 'description' => 'Tyhjentää käännettyjen Blade-näkymien välimuistin.'],
        'queue_retry' => ['label' => 'Yritä epäonnistuneita töitä uudelleen', 'description' => 'Yrittää yhtä epäonnistunutta työtä uudelleen tunnisteen perusteella tai kaikkia, jos annat `all`.'],
        'rederive_fingerprints' => ['label' => 'Laske sormenjäljet uudelleen', 'description' => 'Laskee uudelleen sormenjäljen jokaiselle tapahtumalle, jonka normalisointiversio on yhä nykyistä pienempi. Täältä ajettuna se ilmoittaa määrän eikä kirjoita mitään.'],
        'db_restore' => ['label' => 'Palauta tietokanta', 'description' => 'Korvaa nykyisen tietokannan annetulla varmuuskopiotiedostolla.'],
        'regenerate_recovery_codes' => ['label' => 'Luo palautuskoodit uudelleen', 'description' => 'Luo käyttäjän 10 kertakäyttöistä palautuskoodia uudelleen.'],
        'grant_dev' => ['label' => 'Myönnä kehittäjäoikeudet', 'description' => 'Asettaa annetulle käyttäjälle is_developer=true.'],
        // i18n-review: fi · command.install.description — Idempotentti is a loanword
        // with no settled native form. The sentence relies on the reader knowing
        // the property rather than the word, which a native eye should confirm.
        'install' => ['label' => 'Suorita asennus', 'description' => 'Idempotentti ensiasennus: tietokannan rakenne, viitetiedot ja ainoa käyttäjätili. Uudelleenajo valmiiseen asennukseen vahvistaa olemassa olevan tilin uudelleen eikä muuta salasanaa.'],
    ],

    'arg' => [
        'action' => ['label' => 'Toiminto'],
        'config' => ['label' => 'Asetusavain', 'help' => 'Tulostettava asetustiedosto tai pisteillä eroteltu avain, esimerkiksi `app` tai `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Työn tunniste', 'help' => 'Kirjoita `all`, niin kaikkia epäonnistuneita töitä yritetään uudelleen, tai työn tunniste, niin vain yhtä. Tyhjä kenttä ei yritä mitään uudelleen.', 'placeholder' => 'all (tai tietty tunniste)'],
        'queue' => ['label' => 'Jonon nimi', 'help' => 'Valinnainen jonosuodatin; oletuksena kaikki jonot.', 'placeholder' => 'default'],
        'path' => ['label' => 'Varmuuskopiotiedoston polku', 'help' => 'Korvaa nykyisen tietokannan annetussa polussa olevalla tiedostolla.', 'placeholder' => '/polku/tiedostoon/backup.sqlite'],
        'username' => ['label' => 'Käyttäjätunnus', 'placeholder' => 'alice'],
    ],
];
