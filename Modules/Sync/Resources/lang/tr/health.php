<?php

declare(strict_types=1);

return [
    'title' => 'Senkronizasyon durumu',
    'quarantined_ops' => 'Karantinaya alınan operasyonlar — son 7 gün',
    // i18n-review: tr · skipped — "operasyon" reads as a military or surgical
    // operation to most Turkish speakers; "işlem" is the natural word but already
    // carries "transaction" everywhere else in this locale.
    'skipped' => ':count atlanan operasyon',
    'empty' => 'Son 7 günde atlanan operasyon yok.',
    'col_reason' => 'Neden',
    'col_table' => 'Tablo',
    'col_device' => 'Cihaz',
    'col_when' => 'Ne zaman',
];
