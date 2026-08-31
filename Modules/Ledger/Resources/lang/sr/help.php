<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Usaglašavanje znači uporediti Beatrax s brojkom same banke. Usaglašeni saldo je početni saldo ovog računa plus svaki red koji si do datuma izvoda označio kao izmiren, a razlika je brojka s tvog izvoda minus taj saldo. Označavaj ili odznačavaj redove na listi transakcija dok razlika ne padne na nulu — ovaj ekran nikada ne izmišlja stavku za izravnanje. „:complete” zatim zaključava obuhvaćene redove: zaključan red ne može da se menja, deli ni briše dok ga na njegovoj stranici ponovo ne otključaš.',
];
