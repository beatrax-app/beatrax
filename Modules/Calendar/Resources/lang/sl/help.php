<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Iz katerih računov se gradi napovedano dnevno stanje. Sosednji stolpec „:entries“ odloča o nečem drugem — ali se plačila tega računa sploh izrišejo v mreži — zato je račun lahko viden, ne da bi štel, ali šteje, ne da bi bil viden. Kjer se to dvoje razide, dan to pove pod svojim stanjem, namesto da bi se številka tiho sestavila iz manj, kot pričakuješ.',
];
