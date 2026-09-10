<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Kuriai sąskaitai priskaičiuojamas prognozuojamas mokėjimas. Kai kurie mokėjimai niekada neišeina iš tos sąskaitos, iš kurios atrodo išeiną: pirkinys kortele vėliau padengiamas viena nurašymo operacija iš tavo banko sąskaitos, o mokėjimas iš piniginės finansuojamas pervedimu. Įjungus tai, kiekvienas jų priskaičiuojamas sąskaitai, kuri iš tikrųjų moka, tad sąskaitos linija rodo pinigus, kurie per ją tikrai praeis, o ne tuos, kurie tik prašliaužė pro šalį.',
];
