<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Kom se računu pripisuje predviđeno plaćanje. Neka plaćanja nikad ne napuštaju račun s kojeg izgleda da odlaze: kupovina karticom izmiruje se kasnije jednim zaduženjem tvog bankovnog računa, a plaćanje iz novčanika pokriva se prenosom. Ako ovo uključiš, svako od njih se pripisuje računu koji ga zaista plaća, pa linija računa pokazuje novac koji će kroz njega stvarno proći, a ne onaj koji je tek prošao pored.',
];
