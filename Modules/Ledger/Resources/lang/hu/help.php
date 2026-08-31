<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Az egyeztetés annyit tesz, hogy a Beatraxot a bank saját számához méred. Az egyeztetett egyenleg ennek a számlának a nyitó egyenlege plusz minden sor, amelyet a kivonat dátumáig kiegyenlítettként pipáltál ki, a különbség pedig a kivonatod száma mínusz ez az egyenleg. Pipáld ki vagy vedd ki a pipát a tranzakciólistán, amíg a különbség nullára nem áll — ez a képernyő soha nem talál ki kiegyenlítő tételt. A „:complete” ezután lezárja az érintett sorokat: egy lezárt sort nem lehet szerkeszteni, felosztani vagy törölni, amíg a saját oldaláról fel nem oldod.',
];
