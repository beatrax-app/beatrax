<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Vienas mokėjimas dažnai apmoka kelis kitus: kortelės atsiskaitymas banko sąskaitoje padengia mėnesį pirkimų kortele, o išėmimas iš banko finansuoja piniginės mokėjimą, atliktą prieš kelias dienas. Grandinė užrašo, kuris nurašymas už ką sumokėjo, kad pirkimą viename išraše būtų galima atsekti iki pinigų, kurie iš tikrųjų išėjo iš tavo sąskaitos. Beatrax pati susieja tuos atvejus, kuriais yra tikra, o likusius palieka tau peržiūros eilėje. Kelis kartus patvirtink tokį patį ryšį – ir ji nustos apie tokį klausti.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Užuomina yra pusė ryšio: Beatrax rado vieną mokėjimų grandinės galą, o kito ne, tad užsirašė, ką matė, užuot spėliojusi likusią dalį. Dauguma išsisprendžia savaime — atsiskaitymas laukia čia, kol bus importuotos atskiros išlaidos, kurias jis apmokėjo, ir tada tampa tikra grandine. Likusias drąsiai atmesk; tavo įrašuose taip ar taip niekas nepasikeis.',
];
