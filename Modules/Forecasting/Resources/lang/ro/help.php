<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Cărui cont i se atribuie o plată estimată. Unele plăți nu părăsesc niciodată contul din care par să plece: o cumpărătură cu cardul se decontează mai târziu printr-o singură debitare din contul tău bancar, iar o plată din portofel este alimentată de un transfer. Cu asta pornit, fiecare dintre ele se atribuie contului care o plătește cu adevărat, așa că linia unui cont arată banii care vor trece chiar prin el, nu pe cei care doar au trecut pe alături.',
];
