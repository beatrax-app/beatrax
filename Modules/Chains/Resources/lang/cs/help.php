<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedna platba často platí za několik dalších: vyúčtování karty na bankovním účtu pokryje měsíc nákupů kartou a výběr z banky financuje platbu z peněženky provedenou o pár dní dřív. Řetězec zaznamenává, který odchozí pohyb za co zaplatil, takže nákup na jednom výpisu lze dohledat až k penězům, které z účtu opravdu odešly. Beatrax si jisté případy propojí sám a zbytek nechá ve frontě k posouzení pro tebe. Potvrď stejný druh propojení několikrát a na tento druh se přestane ptát.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Nápověda je půlka propojení: Beatrax našel jednu stranu platebního řetězce a druhou ne, a tak si zapsal, co viděl, místo aby zbytek hádal. Většina se vyřeší sama — vyrovnání tu čeká, dokud nedorazí jednotlivé platby, které uhradilo, a pak se z něj stane opravdový řetězec. Zbytek můžeš klidně odmítnout a v tvých záznamech se tak jako tak nic nezmění.',
];
