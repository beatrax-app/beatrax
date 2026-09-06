<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Näkymä',
        'money' => 'Raha',
        'insights' => 'Havainnot ja hälytykset',
        'security' => 'Turvallisuus ja laitteet',
        'data' => 'Tuonnit ja tiedot',
        'app' => 'Sovellus',
    ],

    'title' => 'Asetukset',
    'subtitle' => 'Asetukset sille, miten taloutesi näkyy sovelluksessa.',

    'appearance' => [
        'heading' => 'Ulkoasu',
        'theme' => 'Teema',
        'theme_light' => 'Vaalea',
        'theme_dark' => 'Tumma',
        'theme_system' => 'Järjestelmä',
        'theme_help' => 'Järjestelmä seuraa käyttöjärjestelmäsi vaaleaa tai tummaa asetusta.',
    ],

    'language' => [
        'apply' => 'Ota käyttöön',
        'heading' => 'Kieli',
        'label' => 'Käyttöliittymän kieli',

        'system' => 'Järjestelmä',
        'help' => 'Vaihtaa näytöllä näkyvät sanat ja sen, miten summat kirjoitetaan. Järjestelmä seuraa selaimesi tai käyttöjärjestelmäsi kieltä ja käyttää oletuksena englantia.',
    ],

    'sample_data' => [
        'heading' => 'Esimerkkidata',
        'help' => 'Täyttää tämän tilin keksityllä kirjanpidolla — tilit, tapahtumat, budjetit, tavoitteet ja ilmoitukset — jotta katsottavaa on. Se lisätään olemassa olevaan, eikä mikään siitä ole oikean ihmisen tietoja.',
        'warning' => 'Tämä kirjoittaa omaan kirjanpitoosi ja päätyy pariliitettyihin laitteisiisi. Tällä näytöllä ei ole kumoamista.',
        'confirm' => 'Lisää se tälle tilille',
        'cancel' => 'Peruuta',
        'load' => 'Lataa esimerkkidata',
        'working' => 'Esimerkkikirjanpitoa rakennetaan. Tämä kestää hetken.',
        'loaded' => 'Esimerkkidata lisätty (:count).',
    ],

    'country' => [
        'heading' => 'Maa',
        'label' => 'Maasi',
        'help' => 'Määrittää, minkä maan verosäännöt, viranomaiset ja pankkikulut sovellus tunnistaa. Se ei vaihda kieltä eikä sitä, miten summat kirjoitetaan.',
        'choose' => 'Valitse maa…',
        'switch_note' => 'Vaihtaminen lisää uusia kategorioita — olemassa olevia merkintöjä ei muuteta.',

        'wording_note' => 'Veroluokkien nimet näkyvät omalla kielelläsi; :country veroilmoitus käyttää omia sanojaan.',

        'countries' => [
            'at' => 'Itävalta',
            'be' => 'Belgia',
            'bg' => 'Bulgaria',
            'ca' => 'Kanada',
            'ch' => 'Sveitsi',
            'cy' => 'Kypros',
            'cz' => 'Tšekki',
            'de' => 'Saksa',
            'dk' => 'Tanska',
            'ee' => 'Viro',
            'es' => 'Espanja',
            'fi' => 'Suomi',
            'fr' => 'Ranska',
            'gb' => 'Yhdistynyt kuningaskunta',
            'gr' => 'Kreikka',
            'hr' => 'Kroatia',
            'hu' => 'Unkari',
            'ie' => 'Irlanti',
            'is' => 'Islanti',
            'it' => 'Italia',
            'lt' => 'Liettua',
            'lu' => 'Luxemburg',
            'lv' => 'Latvia',
            'mt' => 'Malta',
            'nl' => 'Alankomaat',
            'no' => 'Norja',
            'pl' => 'Puola',
            'pt' => 'Portugali',
            'ro' => 'Romania',
            'se' => 'Ruotsi',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'us' => 'Yhdysvallat',
        ],
    ],

    'currency_display' => [
        'heading' => 'Summan näyttö',
        'label' => 'Summien oletusnäkymä',
        'eur_only' => 'Tilitetty summa',
        'original' => 'Alkuperäinen summa',
        'help' => 'Koskee tapahtumalistaa ja Yleisnäkymän summia. Voit silti vaihtaa näkymää sivukohtaisesti, mutta vain tapahtumalistalla.',
    ],

    'base_currency' => [
        'heading' => 'Raportointivaluutta',
        'label' => 'Raportointivaluutta',
        'help' => 'Kaikki summat ja koosteet muunnetaan tähän valuuttaan. Jokainen tili näyttää silti rinnalla oman alkuperäisen valuuttansa.',
    ],

    'exchange_rates' => [
        'heading' => 'Valuuttakurssit',
        'fetch_online' => 'Hae ajantasaiset kurssit verkosta',
        'online_on' => 'Kurssit haetaan päivittäin lähteestä ECB tai lähteestä Frankfurter, jos ECB ei vastaa. Vain valuuttaparien haut — ei henkilötietoja.',
        'last_updated' => 'Päivitetty viimeksi: :date.',
        'online_off' => 'Jo tallessa olevat kurssit ovat yhä käytössä, ja mukana toimitettu tilannevedos toimii varana. Mitään tietoja ei poistu tältä laitteelta.',
        'fetch_aria' => 'Hae ajantasaiset valuuttakurssit verkosta',
        'refreshing' => 'Päivitetään…',
        'next_refresh' => 'Automaattinen päivitys: kerran päivässä',
        'refresh_gave_up' => 'Kursseja ei voitu päivittää. Laitteella jo olevat kurssit ovat yhä käytössä.',
        'refresh_now' => 'Päivitä nyt',
    ],

    'period' => [
        'heading' => 'Jakso',
        'label' => 'Jakso alkaa päivänä',
        'help' => 'Numeroitu 1–28. Useimmat pitävät tämän arvossa 1 (kalenterikuukausi). Käytä arvoa 25, jos palkkasi maksetaan 25. päivä ja ajattelet oman kuukautesi alkavan silloin.',

        'move_confirm' => 'Jos jakso alkaa päivänä :day, kaikki kuorien summat järjestetään uudelleen ja lasketaan yhteen siellä, missä kaksi kuukautta sulautuu yhdeksi. Päivän palauttaminen ei erottele niitä uudelleen.',
        'move_cancel' => 'Peruuta',
        'move_apply' => 'Ota käyttöön',
    ],

    'recurring' => [
        'heading' => 'Toistuvien tunnistus',
        'window_label' => 'Tunnistusikkuna (kuukautta)',
        'window_help' => 'Kuinka monen kuukauden historia käydään läpi, kun tapahtumia ryhmitellään toistuviksi kaavoiksi.',
        'income_label' => 'Tulojen vähimmäismäärä (pienimmät yksiköt)',
        'income_help' => 'Tätä rajaa pienempiä tuloja ei ryhmitellä automaattisesti. Tallennetaan pienimpinä yksikköinä — :minor tarkoittaa :example. Poista raja käytöstä asettamalla arvoksi 0.',
    ],

    'drift' => [
        'heading' => 'Hinnanmuutoshälytykset',
        'label' => 'Hinnanmuutoshälytyksen oletusraja',
        'help' => 'Hälytys laukeaa, kun toistuvan veloituksen viimeisin summa poikkeaa edellisestä enemmän kuin tämän prosenttiosuuden verran. Sarjakohtaiset asetukset menevät tämän edelle.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (oletus)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Tallenna asetukset',
    'saved' => 'Tallennettu.',

    'anomaly_heading' => 'Poikkeamien tunnistus',
    'notifications_heading' => 'Ilmoitukset',

    'forecasting' => [
        'heading' => 'Ennustaminen',
        'intro' => 'Beatrax ennustaa saldosi kehityksen tiliesi nykytilasta eteenpäin. Aseta tässä alkusaldo tileille, joilla ei ole tiliotteen saldoa (PayPal, vanhat CSV-tuonnit), jotta ennusteet lähtevät tunnetusta pisteestä.',
        'no_accounts' => 'Ei vielä tilejä — lisää tili tuomalla tiliote.',
    ],

    'auto_import' => [
        'heading' => 'Automaattinen tuonti',
        'label' => 'Automaattinen tuonti pudotuskansiosta',

        'active_html' => 'Pudotuskansio on käytössä. Beatrax etsii uusia tiedostoja kansiosta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> viiden minuutin välein.',
        'inactive_html' => 'Kun tämä on päällä, Beatrax etsii kansiosta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> viiden minuutin välein <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- ja <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-tiedostoja ja tuo ne saman tunnistinputken kautta kuin ohjattu tuonti. Käsitellyt tiedostot siirtyvät kansioon <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, jottei niitä koskaan tuoda kahdesti.',
        'active_phone_html' => 'Pudotuskansio on käytössä. Beatrax etsii uusia tiedostoja kansiosta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> taustalla. Puhelimesi päättää, milloin taustahaku suoritetaan, joten siihen voi kulua minuutteja tai tunteja.',
        'inactive_phone_html' => 'Kun tämä on päällä, Beatrax etsii kansiosta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> taustalla <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- ja <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-tiedostoja ja tuo ne saman tunnistinputken kautta kuin ohjattu tuonti. Puhelimesi päättää, milloin taustahaku suoritetaan, joten siihen voi kulua minuutteja tai tunteja. Käsitellyt tiedostot siirtyvät kansioon <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, jottei niitä koskaan tuoda kahdesti.',
    ],

    'aliases' => [
        'heading' => 'Aliakset',
        'intro' => 'Tarkastele ja muokkaa selkeitä nimiä, jotka olet opettanut Beatraxille kryptisille tiliotekuvauksille.',
        'manage' => 'Hallitse aliaksia →',
    ],

    'tax_heading' => 'Verot',
    'data_backup_heading' => 'Tiedot ja varmuuskopiot',

    'about_updates' => [
        'heading' => 'Tietoa päivityksistä',
        'body' => 'Beatrax päivittää itsensä automaattisesti asennuksen jälkeen. Kun olet asentanut aivan ensimmäisen version, tulevat versiot saapuvat sovelluksen sisäisellä ilmoituspalkilla — GitHubiin ei tarvitse palata. Jos jokin tuleva päivitys ei asennu, voit aina ladata uusimman asennusohjelman käsin julkaisusivulta.',
        'body_phone' => 'Täällä Beatrax ei päivitä itseään. Puhelinsovelluksen uudet versiot tulevat App Storen tai Google Playn kautta, kuten muutkin sovelluksesi.',
        'check_label' => 'Tarkista päivitykset automaattisesti',
        'check_on' => 'Beatrax kysyy julkaisusyötteeltä, onko uudempaa allekirjoitettua versiota olemassa. Mitään ei ladata, ennen kuin valitset itse asennuksen.',
        'check_off' => 'Päivityksiä ei tarkisteta eikä mitään lähde tältä laitteelta. Uudet versiot löydät avaamalla julkaisusivun itse.',
        'open_releases' => 'Avaa julkaisusivu →',
    ],

    'privacy' => [
        'heading' => 'Tietosuojakäytäntö',
        'body' => 'Beatrax pitää raha-asiasi omilla laitteillasi. Käytäntö kertoo, mitä se tarkoittaa, mitä valinnaiset verkkotoiminnot lähettävät ja miten poistat tietosi.',
        'open' => 'Lue tietosuojakäytäntö →',
        'url_hint' => 'Jos linkki ei aukea, mene osoitteeseen:',
    ],

    'first_run_tour' => [
        'heading' => 'Aloituskierros',
        'body' => 'Käynnistä ohjattu käyttöönotto uudelleen, jos haluat käydä esittelyn läpi uudestaan.',
        'run_again' => 'Käynnistä ohjattu käyttöönotto uudelleen',
    ],

    'developer' => [
        'heading' => 'Kehittäjä',
        'label' => 'Sovelluksen sisäinen kehityskonsoli',
        'help' => 'Näytä kehityskonsoli osoitteessa /dev. Nollaa Lisäasetukset-valinnan jokaisella kirjautumisella.',
        'aria' => 'Kehitystila',
    ],

    'errors' => [
        'period_move_failed' => 'Budjettikuukautta ei voitu siirtää, joten se jäi ennalleen.',
        'currency_required' => 'Valitse valuutta.',
        'window_months' => 'Valitse 2–60 kuukautta.',
        'threshold' => 'Valitse raja vaihtoehdoista 1 %, 2 %, 5 %, 10 %, 25 % tai 50 %.',
        'amount' => 'Anna summa, joka on vähintään :zero.',
        'period_day' => 'Valitse päivä väliltä 1–28.',
        'currency_view' => 'Valitse jokin käytettävissä olevista vaihtoehdoista.',
    ],
];
