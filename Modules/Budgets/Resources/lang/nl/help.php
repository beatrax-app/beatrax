<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Geld dat binnen is en nog geen envelop heeft: de inkomsten van deze periode, plus wat je vorige periode niet had toegewezen, min alles wat hieronder is toegewezen. Breng het naar nul en er blijft niets onverdeeld. Onder nul betekent dat je meer hebt toegewezen dan er werkelijk is binnengekomen — haal iets terug uit een envelop of wacht op het volgende salaris.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Wat er met een envelop gebeurt die meer heeft uitgegeven dan erin zit, zodra de periode voorbij is. Kies je ‘:reduce’, dan gaat het tekort er meteen af van wat je volgende periode te verdelen hebt, en begint de envelop zelf weer op nul. Kies je ‘:carry’, dan blijft het tekort staan waar het is ontstaan: die envelop begint onder nul en moet eerst worden aangevuld voordat er weer iets uit betaald wordt, en de rest van het plan blijft ongemoeid.',
];
