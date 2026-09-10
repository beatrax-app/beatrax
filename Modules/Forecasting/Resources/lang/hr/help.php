<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Na koji se račun broji predviđeno plaćanje. Neka plaćanja nikad ne napuštaju račun s kojeg se čini da odlaze: kupnja karticom podmiruje se poslije jednim terećenjem tvog bankovnog računa, a plaćanje iz novčanika pokriva se prijenosom. Uključi li se ovo, svako se od njih broji na račun koji ga zaista plaća, pa linija računa pokazuje novac koji će kroz njega stvarno proći, a ne onaj koji je tek prošao pokraj.',
];
