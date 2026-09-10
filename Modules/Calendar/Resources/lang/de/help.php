<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Aus welchen Konten der projizierte Tagessaldo gebildet wird. Die Spalte daneben, „:entries“, entscheidet etwas anderes — ob die Zahlungen dieses Kontos überhaupt im Raster gezeichnet werden — ein Konto kann also sichtbar sein, ohne zu zählen, oder zählen, ohne sichtbar zu sein. Wo beide auseinandergehen, sagt der Tag es unter seinem Saldo, statt eine Zahl stillschweigend aus weniger zu bilden, als du erwartest.',
];
