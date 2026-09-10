<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Millistest kontodest ennustatav päevajääk kokku pannakse. Kõrvalveerg „:entries“ otsustab midagi muud — kas selle konto maksed üldse ruudustikku joonistatakse — nii et konto võib olla näha, ilma et ta loeks, või lugeda, ilma et ta näha oleks. Kui need kaks lähevad lahku, ütleb päev seda oma jäägi all, selle asemel et arv paneks end vaikselt kokku vähemast, kui sa ootad.',
];
