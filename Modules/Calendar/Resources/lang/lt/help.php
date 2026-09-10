<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Iš kokių sąskaitų sudaromas prognozuojamas dienos likutis. Gretimas stulpelis „:entries“ sprendžia ką kita — ar tos sąskaitos mokėjimai apskritai piešiami tinklelyje — tad sąskaita gali būti matoma ir neskaičiuojama arba skaičiuojama ir nematoma. Kai šie du išsiskiria, diena tai pasako po savo likučiu, o ne leidžia skaičiui tyliai susidėti iš mažiau, nei tikiesi.',
];
