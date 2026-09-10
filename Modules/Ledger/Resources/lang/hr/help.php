<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Usklađivanje znači usporediti Beatrax s brojkom same banke. Usklađeni saldo je početni saldo ovog računa plus svaki redak koji si do datuma izvatka označio kao podmiren, a razlika je brojka s tvog izvatka minus taj saldo. Označavaj ili odznačuj retke na popisu transakcija dok razlika ne padne na nulu — ovaj zaslon nikada ne izmišlja stavku za izravnanje. „:complete” zatim zaključava obuhvaćene retke: zaključani se redak ne može uređivati, dijeliti ni brisati dok ga na njegovoj stranici ponovno ne otključaš.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Gdje redak stoji u odnosu na tvoj bankovni izvadak. „:uncleared” znači da Beatrax ima transakciju, ali je ti još nisi povezao s izvatkom; dodirni pilulu da postane „:cleared” — upravo te kvačice zaslon usklađivanja zbraja. „:reconciled” se ne dodiruje: postavlja ga dovršeno usklađivanje, koje zaključa redak, pa kategorija, bilješka, podjela i porezne oznake ostaju kakve jesu dok ga opet ne otključaš.',
];
