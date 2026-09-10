<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Usaglašavanje znači uporediti Beatrax s brojkom same banke. Usaglašeni saldo je početni saldo ovog računa plus svaki red koji si do datuma izvoda označio kao izmiren, a razlika je brojka s tvog izvoda minus taj saldo. Označavaj ili odznačavaj redove na listi transakcija dok razlika ne padne na nulu — ovaj ekran nikada ne izmišlja stavku za izravnanje. „:complete” zatim zaključava obuhvaćene redove: zaključan red ne može da se menja, deli ni briše dok ga na njegovoj stranici ponovo ne otključaš.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Gde red stoji u odnosu na tvoj bankovni izvod. „:uncleared” znači da Beatrax ima transakciju, ali je ti još nisi povezao s izvodom; dodirni pilulu da postane „:cleared” — upravo te kvačice ekran usaglašavanja sabira. „:reconciled” se ne dodiruje: postavlja ga dovršeno usaglašavanje, koje zaključava red, pa kategorija, beleška, podela i poreske oznake ostaju kakve jesu dok ga opet ne otključaš.',
];
