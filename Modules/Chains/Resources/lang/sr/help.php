<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedno plaćanje često plaća nekoliko drugih: obračun kartice na bankovnom računu pokriva mesec dana kupovina karticom, a podizanje s banke finansira plaćanje iz novčanika obavljeno danima ranije. Lanac beleži koje je zaduženje šta platilo, pa kupovina s jednog izvoda može da se prati sve do novca koji je zaista otišao s tvog računa. Beatrax sigurne slučajeve poveže sam, a ostale ostavlja tebi u redu za pregled. Potvrdi istu vrstu veze nekoliko puta i prestaće da pita za tu vrstu.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Savet je pola veze: Beatrax je našao jednu stranu platnog lanca, a drugu nije, pa je zapisao šta je video umesto da ostatak pogađa. Većina se reši sama — izmirenje ovde čeka dok se ne uvezu pojedinačni troškovi koje je platilo, i tada postaje pravi lanac. Ostale slobodno odbaci; u tvojim knjigama se ionako ništa ne menja.',
];
