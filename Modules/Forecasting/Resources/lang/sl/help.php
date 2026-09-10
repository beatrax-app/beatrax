<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Kateremu računu se pripiše napovedano plačilo. Nekatera plačila nikoli ne zapustijo računa, s katerega je videti, da odhajajo: nakup s kartico se pozneje poravna z eno bremenitvijo tvojega bančnega računa, plačilo iz denarnice pa se pokrije s prenosom. Če to vklopiš, se vsako od njih pripiše računu, ki ga zares plača, zato črta računa kaže denar, ki bo skozenj res šel, in ne tistega, ki je le šel mimo.',
];
