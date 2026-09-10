<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Co potwierdzenie zrobi z każdym wierszem. „:new” trafia do twoich zapisów; „:duplicate” jest już tam z wcześniejszego importu i zostaje pominięty, więc ponowne wczytanie wyciągu, który zachodzi na już wczytany, nic nie kosztuje; „:enriched” pasuje do wiersza, który już masz, i uzupełnia szczegół, którego pierwszy plik nie niósł, nie dodając drugiego. Jak dotąd nic na tym ekranie nie ruszyło twoich zapisów — „:confirm” to ta chwila.',
];
