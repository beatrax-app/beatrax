<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Rodymas',
        'money' => 'Pinigai',
        'insights' => 'Įžvalgos ir įspėjimai',
        'security' => 'Sauga ir įrenginiai',
        'data' => 'Importai ir duomenys',
        'app' => 'Programėlė',
    ],

    'title' => 'Nustatymai',
    'subtitle' => 'Nuostatos, kaip programėlėje rodomi tavo finansai.',

    'appearance' => [
        'heading' => 'Išvaizda',
        'theme' => 'Tema',
        'theme_light' => 'Šviesi',
        'theme_dark' => 'Tamsi',
        'theme_system' => 'Sistemos',
        'theme_help' => 'Sistemos parinktis seka tavo operacinės sistemos šviesų arba tamsų nustatymą.',
    ],

    'language' => [
        'apply' => 'Taikyti',
        'heading' => 'Kalba',
        'label' => 'Sąsajos kalba',

        'system' => 'Sistemos',
        'help' => 'Sistemos parinktis seka tavo naršyklės arba operacinės sistemos kalbą; numatytoji — anglų.',
    ],

    'currency_display' => [
        'heading' => 'Valiutos rodymas',
        'label' => 'Numatytasis rodinys operacijų sąraše',
        'eur_only' => 'Tik EUR',
        'original' => 'Pradinė valiuta',
        'help' => 'Kiekviename puslapyje vis tiek gali persijungti iš operacijų sąrašo.',
    ],

    'base_currency' => [
        'heading' => 'Ataskaitų valiuta',
        'label' => 'Ataskaitų valiuta',
        'help' => 'Visos sumos ir suvestinės perskaičiuojamos į šią valiutą. Kiekviena sąskaita greta vis tiek rodo savo pradinę valiutą.',
    ],

    'exchange_rates' => [
        'heading' => 'Valiutų kursai',
        'fetch_online' => 'Gauti dabartinius kursus internetu',
        'online_on' => 'Kursai kasdien gaunami iš ECB. Užklausiamos tik valiutų poros — jokių asmens duomenų.',
        'last_updated' => 'Paskutinį kartą atnaujinta: :date.',
        'online_off' => 'Naudojami kartu pateikti kursai. Jokie duomenys neišeina iš šio įrenginio.',
        'fetch_aria' => 'Gauti dabartinius valiutų kursus internetu',
        'refreshing' => 'Atnaujinama…',
        'next_refresh' => 'Kitas automatinis atnaujinimas: kasdien 09:00',
        'refresh_gave_up' => 'Nepavyko atnaujinti kursų. Toliau naudojami šiame įrenginyje jau esantys kursai.',
        'refresh_now' => 'Atnaujinti dabar',
    ],

    'period' => [
        'heading' => 'Laikotarpis',
        'label' => 'Laikotarpis prasideda dieną',
        'help' => 'Nuo 1 iki 28. Dauguma naudotojų palieka 1 (kalendorinis mėnuo). Pasirink 25, jei atlyginimą gauni 25 dieną ir „savo mėnesį“ skaičiuoji nuo tada.',
    ],

    'recurring' => [
        'heading' => 'Pasikartojančių mokėjimų aptikimas',
        'window_label' => 'Aptikimo langas (mėnesiais)',
        'window_help' => 'Kiek istorijos mėnesių nuskaityti grupuojant operacijas į pasikartojančius modelius.',
        'income_label' => 'Mažiausios pajamos (centais)',
        'income_help' => 'Už šią ribą mažesnės pajamos automatiškai negrupuojamos. Saugoma centais — 200000 reiškia 2 000,00 €. Nustatyk 0, kad ribos nebūtų.',
    ],

    'drift' => [
        'heading' => 'Pokyčio įspėjimai',
        'label' => 'Numatytoji pokyčio įspėjimo riba',
        'help' => 'Įspėjimai siunčiami, kai naujausia pasikartojančio mokėjimo suma nuo ankstesnės skiriasi daugiau nei šia procentine dalimi. Atskiroms serijoms nustatytos reikšmės turi pirmenybę.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (numatytoji)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Išsaugoti nustatymus',
    'saved' => 'Išsaugota.',

    'anomaly_heading' => 'Anomalijų aptikimas',
    'notifications_heading' => 'Pranešimai',

    'forecasting' => [
        'heading' => 'Prognozavimas',
        'intro' => 'Beatrax prognozuoja tavo likutį nuo dabartinės sąskaitų būklės. Sąskaitoms, kurios neturi išrašo likučių (PayPal, seni CSV importai), čia nurodyk pradinį likutį, kad prognozės prasidėtų nuo žinomo taško.',
        'no_accounts' => 'Kol kas sąskaitų nėra — importuok išrašą, kad pridėtum.',
    ],

    'auto_import' => [
        'heading' => 'Automatinis importas',
        'label' => 'Automatinis importas iš įkėlimo aplanko',

        'active_html' => 'Įkėlimo aplankas aktyvus. Beatrax kas 5 minutes tikrina <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, ar nėra naujų failų.',
        'inactive_html' => 'Kai įjungta, Beatrax kas 5 minutes tikrina <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, ar nėra <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> ir <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> failų, ir importuoja juos tuo pačiu derinimo konvejeriu kaip ir vediklis. Apdoroti failai perkeliami į <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, kad niekada nebūtų importuoti du kartus.',
    ],

    'aliases' => [
        'heading' => 'Alternatyvūs pavadinimai',
        'intro' => 'Peržiūrėk ir redaguok aiškius pavadinimus, kurių išmokei Beatrax neaiškiems išrašų aprašymams.',
        'manage' => 'Tvarkyti alternatyvius pavadinimus →',
    ],

    'tax_heading' => 'Mokesčiai',
    'shared_merchant_heading' => 'Bendras prekybininkų sąrašas',
    'data_backup_heading' => 'Duomenys ir atsarginės kopijos',
    'install_heading' => 'Diegimas',

    'about_updates' => [
        'heading' => 'Apie atnaujinimus',
        'body' => 'Įdiegta Beatrax atsinaujina automatiškai. Įdiegus pačią pirmąją versiją, būsimos versijos pasiekia tave per programėlės juostą — grįžti į GitHub nereikia. Jei kada nors atnaujinimo nepavyktų pritaikyti, naujausią diegimo failą visada gali ranka atsisiųsti iš laidų puslapio.',
        'open_releases' => 'Atverti laidų puslapį →',
    ],

    'privacy' => [
        'heading' => 'Privatumo politika',
        'body' => 'Beatrax laiko tavo finansus tavo paties įrenginiuose. Politika paaiškina, ką tai reiškia, ką siunčia pasirenkamos internetinės funkcijos ir kaip pašalinti savo duomenis.',
        'open' => 'Skaityti privatumo politiką →',
        'url_hint' => 'Jei nuoroda neatsidaro, apsilankyk:',
    ],

    'first_run_tour' => [
        'heading' => 'Pirmojo paleidimo apžvalga',
        'body' => 'Paleisk sąrankos vediklį iš naujo, jei nori dar kartą pereiti įvadinį srautą.',
        'run_again' => 'Paleisti sąrankos vediklį iš naujo',
    ],

    'developer' => [
        'heading' => 'Kūrėjas',
        'label' => 'Programėlės kūrėjo pultas',
        'help' => 'Rodyti kūrėjo pultą adresu /dev. Kiekvieną kartą prisijungus atstato „Išplėstinis“ jungiklį.',
        'aria' => 'Kūrėjo režimas',
    ],

    'errors' => [
        'currency_required' => 'Pasirink valiutą.',
        'window_months' => 'Pasirink nuo 2 iki 60 mėnesių.',
        'threshold' => 'Pasirink ribą iš 1%, 2%, 5%, 10%, 25% arba 50%.',
        'amount' => 'Įvesk sumą nuo 0 € ir daugiau.',
        'period_day' => 'Pasirink dieną nuo 1 iki 28.',
        'currency_view' => 'Pasirink vieną iš galimų parinkčių.',
    ],
];
