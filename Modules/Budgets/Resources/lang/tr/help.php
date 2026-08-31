<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Hesabına girmiş ama henüz bir zarfı olmayan para: bu dönemin geliri, artı geçen dönemden dağıtılmadan kalan tutar, eksi aşağıda dağıtılan her şey. Sıfıra indir, hiçbir şey plansız kalmasın. Sıfırın altı, gerçekte girenden fazlasını dağıttığın anlamına gelir — bir zarftan bir miktar geri al ya da bir sonraki maaşı bekle.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Dönem bittiğinde, içinde olandan fazlasını harcamış bir zarfa ne olacağı. “:reduce” seçersen açık, gelecek dönem dağıtacağın tutardan ilk sırada düşülür ve zarfın kendisi yeniden sıfırdan başlar. “:carry” seçersen açık, oluştuğu yerde kalır: o zarf sıfırın altında açılır ve bir şey ödeyebilmek için önce doldurulması gerekir, planın geri kalanı ise yerinden oynamaz.',
];
