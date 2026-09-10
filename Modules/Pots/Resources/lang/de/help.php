<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Eine Rücklage legt Geld innerhalb eines Kontos beiseite, statt es herauszunehmen — der Kontostand bei deiner Bank ändert sich also nicht, wenn du einzahlst oder entnimmst. Jedes Konto zeigt drei Beträge: „:real“ ist das, was die Bank hält, „:allocated“ ist das, was deine Rücklagen zusammen beansprucht haben, und „:unallocated“ ist der Rest — der einzige Teil, der noch frei ausgegeben werden kann. Fällt dieser letzte Betrag unter null, beanspruchen die Rücklagen mehr, als auf dem Konto liegt, und jeder Rücklagenstand ist zu hoch angesetzt, bis du etwas zurücknimmst.',
];
