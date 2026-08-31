<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedna platba často platí za několik dalších: vyúčtování karty na bankovním účtu pokryje měsíc nákupů kartou a výběr z banky financuje platbu z peněženky provedenou o pár dní dřív. Řetězec zaznamenává, který odchozí pohyb za co zaplatil, takže nákup na jednom výpisu lze dohledat až k penězům, které z účtu opravdu odešly. Beatrax si jisté případy propojí sám a zbytek nechá ve frontě k posouzení pro tebe. Potvrď stejný druh propojení několikrát a na tento druh se přestane ptát.',
];
