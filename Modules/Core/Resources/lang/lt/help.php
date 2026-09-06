<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Apie :subject',
        'close' => 'Uždaryti',
    ],

    'page_title' => 'Kur yra mano duomenys?',
    'intro' => 'Beatrax viską saugo šiame įrenginyje. Nėra jokio Beatrax serverio ir jokios paskyros debesijoje. Savaime iškeliauja tik viena užklausa — naujos versijos patikra, kurią gali ir išjungti. Visa kita laukia tavęs: pašto dėžutė, bankas per Enable Banking, kasdienė valiutų kursų užklausa, įrenginiai, kuriuos susieji sinchronizavimui, tavo nustatytas retransliavimo serveris ir kiekviena nuoroda, kurią paspaudi. Kiekvienas iš jų tai pasako tame lange, kuriame jį įjungi.',

    'lives_here' => 'Tavo duomenys yra čia',
    'copy' => 'Kopijuoti',
    'copied' => 'Nukopijuota',

    'location' => [
        'database' => 'Duomenų bazė:',
        'artefacts_imports' => 'Importuoti išrašai:',
        'artefacts_mail' => 'Nuskaitytas paštas:',
        'artefacts_drop' => 'Stebimas aplankas:',
        'backups' => 'Atsarginės kopijos:',
        'secrets' => 'Jungčių prisijungimo duomenys:',
        'logs' => 'Žurnalai:',
    ],

    'copy_aria' => [
        'database' => 'Kopijuoti duomenų bazės kelią į iškarpinę',
        'artefacts_imports' => 'Kopijuoti importuotų išrašų kelią į iškarpinę',
        'artefacts_mail' => 'Kopijuoti nuskaityto pašto kelią į iškarpinę',
        'artefacts_drop' => 'Kopijuoti stebimo aplanko kelią į iškarpinę',
        'backups' => 'Kopijuoti atsarginių kopijų kelią į iškarpinę',
        'secrets' => 'Kopijuoti jungčių prisijungimo duomenų kelią į iškarpinę',
        'logs' => 'Kopijuoti žurnalų kelią į iškarpinę',
    ],

    'artefacts_heading' => 'Tavo pirminiai dokumentai nėra atsarginėje kopijoje',
    'artefacts_body' => 'Atsarginėje kopijoje yra duomenų bazė ir daugiau nieko. Išrašai, kuriuos importavai, paštas, kurį parsisiuntė skaitytuvas, ir kvitai, kuriuos įmetei į stebimą aplanką, lieka ten, kur buvo — trijuose aukščiau išvardytuose aplankuose. Padėjus atsarginę kopiją saugioje vietoje jie nenukopijuojami, tad visas archyvas reiškia pasiimti ir tuos aplankus — arba pasinaudoti žemiau esančiu „Eksportuoti viską“, kuris supakuoja juos kartu su atsargine kopija.',

    'export_heading' => 'Eksportuoti viską',
    'export_body' => 'Vienas archyvas su užšifruota tavo duomenų bazės kopija ir kiekvienu pirminiu dokumentu, kurį atidavei Beatrax. Išskleisk jį kur nori — dokumentai bus viduje tokie, kokie visada buvo, tuose pačiuose aplankuose, iš kurių atkeliavo.',
    'export_passphrase_label' => 'Duomenų bazės slaptafrazė',
    'export_confirm_label' => 'Pakartok slaptafrazę',
    'export_passphrase_hint' => 'Archyve esanti duomenų bazė šifruojama šia slaptafraze ir be jos jos atverti nepavyks, tad pasirink tokią, kurią tikrai išsaugosi. Pirminiai dokumentai patenka į archyvą tokie, kokie yra, tad laikyk archyvą ten, kuo pasitiki.',
    'export_cta' => 'Eksportuoti viską į ZIP',
    'export_working' => 'Kuriamas archyvas…',

    'delete_heading' => 'Duomenų ištrynimas',
    'delete_intro' => 'Tavo duomenys yra failai šiame įrenginyje, tad juos ištrinti reiškia ištrinti tuos failus. Čia nėra mygtuko, kuris tai padarytų už tave, ir tai sąmoningai: tavo istoriją iš tikrųjų laiko failų sistema, o mygtukas, kuris ištuštintų kelias lenteles palikdamas failus vietoje, būtų blogiau nei nieko.',
    'delete_uninstall' => 'Beatrax pašalinimas neištrina tavo duomenų. Tai sąmoninga — atsitiktinis pašalinimas neturi sunaikinti kelerių metų istorijos — todėl viskas, kas išvardyta žemiau, lieka šiame įrenginyje, kol pats to nepašalinsi.',
    'delete_list_intro' => 'Kad nebeliktų jokio pėdsako, ištrink kiekvieną iš šių:',
    'delete_journal_note' => 'Šalia duomenų bazės yra du žurnalo failai, :wal ir :shm. Naujausi tavo pakeitimai lieka juose, kol nesuliejami į duomenų bazę, tad ištrink visus tris kartu.',
    'no_telemetry' => 'Nėra jokios telemetrijos, kurios reikėtų atsisakyti, ir jokios nuotolinės paskyros, kurią reikėtų uždaryti.',
];
