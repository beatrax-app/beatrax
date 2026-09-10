<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Skarbonka odkłada pieniądze wewnątrz konta, zamiast wyprowadzać je z niego, więc saldo w banku nie zmienia się, kiedy ją zasilasz albo z niej wypłacasz. Przy każdym koncie widnieją trzy kwoty: „:real” to, co trzyma bank, „:allocated” to, po co sięgnęły razem twoje skarbonki, a „:unallocated” to reszta — jedyna część, którą wciąż można swobodnie wydać. Kiedy ta ostatnia kwota spadnie poniżej zera, skarbonki sięgają po więcej, niż konto zawiera, i każde saldo jest zawyżone, dopóki czegoś nie zabierzesz z powrotem.',
];
