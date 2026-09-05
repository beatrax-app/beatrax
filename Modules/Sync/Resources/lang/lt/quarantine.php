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
    'last_seen' => 'Naujausia: :when',
];
