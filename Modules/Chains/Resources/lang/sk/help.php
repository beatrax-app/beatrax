<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedna platba často platí za niekoľko ďalších: vyúčtovanie karty na bankovom účte pokryje mesiac nákupov kartou a výber z banky financuje platbu z peňaženky spred pár dní. Reťazec zaznamenáva, ktorý odchádzajúci pohyb za čo zaplatil, takže nákup na jednom výpise sa dá dohľadať až k peniazom, ktoré z účtu naozaj odišli. Beatrax si isté prípady prepojí sám a zvyšok nechá vo fronte na posúdenie pre teba. Potvrď rovnaký druh prepojenia niekoľkokrát a na tento druh sa prestane pýtať.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Tip je polovica prepojenia: Beatrax našiel jednu stranu platobného reťazca a druhú nie, tak si zapísal, čo videl, namiesto toho, aby zvyšok hádal. Väčšina sa vyrieši sama — vyrovnanie tu čaká, kým nedorazia jednotlivé platby, ktoré uhradilo, a potom sa z neho stane skutočný reťazec. Zvyšok môžeš pokojne zamietnuť a v tvojich záznamoch sa tak či tak nič nezmení.',
];
