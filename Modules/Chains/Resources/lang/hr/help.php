<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedno plaćanje često plaća nekoliko drugih: obračun kartice na bankovnom računu pokriva mjesec dana kupnji karticom, a podizanje s banke financira plaćanje iz novčanika obavljeno danima ranije. Lanac bilježi koje je terećenje što platilo, pa se kupnja s jednog izvatka može pratiti sve do novca koji je zaista otišao s tvog računa. Beatrax sigurne slučajeve poveže sam, a ostale ostavlja tebi u redu za pregled. Potvrdi istu vrstu veze nekoliko puta i prestat će pitati za tu vrstu.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Natuknica je pola veze: Beatrax je našao jednu stranu platnog lanca, a drugu nije, pa je zapisao što je vidio umjesto da ostatak pogađa. Većina se riješi sama — podmirenje ovdje čeka dok se ne uvezu pojedinačni troškovi koje je platilo, i tada postaje pravi lanac. Ostale slobodno odbaci; u tvojim se knjigama ionako ništa ne mijenja.',
];
