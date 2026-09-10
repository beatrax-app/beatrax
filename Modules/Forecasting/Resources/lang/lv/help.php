<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Uz kuru kontu tiek attiecināts prognozēts maksājums. Daži maksājumi nekad neaiziet no tā konta, no kura šķiet aizejam: pirkums ar karti vēlāk tiek nokārtots ar vienu norakstījumu no tava bankas konta, bet maksājums no maka tiek segts ar pārskaitījumu. Ieslēdzot šo, katrs no tiem tiek attiecināts uz kontu, kas patiešām maksā, tāpēc konta līnija rāda naudu, kas tam tiešām izies cauri, nevis to, kas tikai pagāja garām.',
];
