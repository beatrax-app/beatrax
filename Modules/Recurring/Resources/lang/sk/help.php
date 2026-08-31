<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Výpis je plochý zoznam dátumov a súm a nič v ňom nehovorí, ktoré riadky sú ten istý trvalý záväzok. Beatrax zoskupí riadky podľa príjemcu platby, zahodí sumy, ktoré zo skupiny vybočujú, a sériu navrhne až vtedy, keď sa odstupy medzi nimi ustália v pravidelnom týždennom, mesačnom, štvrťročnom alebo ročnom rytme — čokoľvek menej pravidelné nenavrhne vôbec. Dozadu číta len tak ďaleko, kam siaha „:setting“ v Nastaveniach, a to začína pri najkratšom úseku, s ktorým vôbec vie pracovať, takže ročná faktúra zostane mimo dohľadu, kým ho nerozšíriš. S tvojimi údajmi sa tu nič nedeje, kým to neschváliš.',
];
