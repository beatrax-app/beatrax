<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count pakeitimą padarė naujesnė Beatrax versija|:count pakeitimus padarė naujesnė Beatrax versija|:count pakeitimų padarė naujesnė Beatrax versija',
        'body' => 'Tai, kas buvo atmesta, nurodo tai, ko ši Beatrax versija neturi, todėl šis įrenginys neturėjo kur to padėti. Tai tebėra įrenginyje, kuris tai padarė, ir nieko tavo nebuvo ištrinta.',
        'action' => 'Atnaujink Beatrax šiame įrenginyje. Po atnaujinimo padaryti pakeitimai ateina įprastai, bet tai, kas jau buvo atmesta, iš naujo nesiunčiama — padaryk pakeitimą čia dar kartą, jei jo reikia ir šiame įrenginyje.',
    ],
    'untrusted_author' => [
        'summary' => ':count pakeitimą pasirašė įrenginys, kurio šis neatpažįsta|:count pakeitimus pasirašė įrenginys, kurio šis neatpažįsta|:count pakeitimų pasirašė įrenginys, kurio šis neatpažįsta',
        'body' => 'Tai, kas buvo atmesta, atėjo iš įrenginio, kuris niekada nebuvo susietas su šiuo, arba iš įrenginio, kurį pašalinai. Čia nieko nebuvo įrašyta ir niekas iš to, kas jau buvo čia, nepasikeitė.',
        'action' => 'Jei tą įrenginį pašalinai pats, kaip tik taip pašalinimas ir veikia, taisyti nieko nereikia. Jei ne, peržiūrėk įrenginių sąrašą šiame puslapyje.',
    ],
    'not_verified' => [
        'summary' => ':count pakeitimas nepraėjo saugumo patikros šiame įrenginyje|:count pakeitimai nepraėjo saugumo patikros šiame įrenginyje|:count pakeitimų nepraėjo saugumo patikros šiame įrenginyje',
        'body' => 'Parašas neatitiko įrenginio, kuris teigė padaręs pakeitimą, arba pakeitimas buvo skirtas kitai paskyrai. Čia nieko nebuvo įrašyta. Tarp tavo paties įrenginių taip neturėtų nutikti.',
        'action' => 'Peržiūrėk įrenginių sąrašą šiame puslapyje ir pašalink visa, ko neatpažįsti. Jei kiekvienas ten esantis įrenginys yra tavo, o tai kartojasi, tai Beatrax programėlės triktis, o ne kažkas, ką galėtum sutvarkyti iš čia.',
    ],
    'diverged' => [
        'summary' => ':count pakeitimas iš kito įrenginio čia nebuvo išsaugotas|:count pakeitimai iš kito įrenginio čia nebuvo išsaugoti|:count pakeitimų iš kito įrenginio čia nebuvo išsaugota',
        'body' => 'Atėjo kai kas, ko šis įrenginys negalėjo išsaugoti: įrašas, kuriam trūksta dalies savęs, data, kurios nėra, padalijimas, kuris nebesutampa, įrašas, kuriam du įrenginiai jau buvo suteikę tą pačią tapatybę, arba trynimas to, kas čia dar naudojama. Tai, kas buvo atmesta, yra tavo kitame įrenginyje, o šiame ne, todėl abu nebeturi to paties.',
        'action' => 'Palygink įrašą savo kitame įrenginyje su tuo, ką matai čia, ir padaryk pakeitimą čia dar kartą — arba čia jį vėl ištrink, jei kas nors, ką pašalinai kitur, vis dar yra čia. Tai, kas buvo atmesta, savaime iš naujo nesiunčiama.',
    ],
    'held' => [
        'summary' => ':count pakeitimas iš kito įrenginio čia dar nepritaikytas|:count pakeitimai iš kito įrenginio čia dar nepritaikyti|:count pakeitimų iš kito įrenginio čia dar nepritaikyta',
        'body' => 'Jie atėjo, bet jų dar nepavyko išsaugoti: to, ką jie nurodo, tuo metu čia dar nebuvo, arba šis įrenginys negalėjo perskaityti jų dalies. Jie yra saugomi, o ne išmetami, ir tebėra įrenginyje, kuris juos padarė.',
        'action' => 'Čia nieko daryti nereikia. Jei jie vis dar rodomi po to, kai tavo įrenginiai vėl susinchronizuos, atidaryk įrašą savo kitame įrenginyje ir palygink jį su tuo, ką matai čia — tai, kas suskaičiuota aukščiau, yra tame įrenginyje, o ne šiame.',
    ],
    'last_seen' => 'Naujausia: :when',
];
