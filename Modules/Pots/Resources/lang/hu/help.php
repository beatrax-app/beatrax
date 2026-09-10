<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'A persely a számlán belül tesz félre pénzt ahelyett, hogy kivinné onnan, így a banki egyenleg nem változik, amikor feltöltöd vagy kiveszel belőle. Minden számlánál három összeg áll: „:real” az, amit a bank tart, „:allocated” az, amire a perselyeid együtt igényt tartanak, „:unallocated” pedig a maradék — az egyetlen rész, ami még szabadon elkölthető. Ha ez az utolsó összeg nulla alá esik, a perselyek többre tartanak igényt, mint amennyi a számlán van, és minden perselyegyenleg túl magas, amíg vissza nem veszel valamennyit.',
];
