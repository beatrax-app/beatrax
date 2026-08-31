<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Pengar som har kommit in och ännu inte har något kuvert: den här periodens inkomster, plus det du lämnade ofördelat förra perioden, minus allt som är fördelat nedan. Få ner det till noll, så är inget oplanerat. Under noll betyder att du har fördelat mer än vad som faktiskt kommit in — ta tillbaka något ur ett kuvert eller vänta på nästa lön.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Vad som händer med ett kuvert som har gjort av med mer än det innehåller, när perioden är slut. Väljer du ”:reduce” dras underskottet först från det du har att fördela nästa period, och kuvertet självt börjar om på noll. Väljer du ”:carry” står underskottet kvar där det uppstod: kuvertet öppnar under noll och måste fyllas på igen innan det betalar för något, och resten av planen rörs inte.',
];
