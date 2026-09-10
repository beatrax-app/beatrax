<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Kumbara parayı hesabın dışına çıkarmak yerine hesabın içinde ayırır; bu yüzden ona para eklediğinde ya da ondan para çektiğinde bankadaki bakiyen değişmez. Her hesapta üç tutar görünür: “:real” bankanın tuttuğu tutardır, “:allocated” kumbaralarının toplamda üzerine aldığı tutardır, “:unallocated” ise geriye kalandır — hâlâ serbestçe harcanabilen tek kısım. Bu son tutar sıfırın altına düştüğünde kumbaralar hesapta olandan fazlasını üzerine almıştır ve sen bir miktarını geri alana kadar her kumbara bakiyesi olduğundan yüksek görünür.',
];
