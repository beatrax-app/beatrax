<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Een potje zet geld apart binnen een rekening in plaats van het eraf te halen, dus je banksaldo verandert niet als je stort of opneemt. Elke rekening toont drie bedragen: ‘:real’ is wat de bank aanhoudt, ‘:allocated’ is wat je potjes samen hebben opgeëist, en ‘:unallocated’ is wat overblijft — het enige deel dat nog vrij te besteden is. Zakt dat laatste bedrag onder nul, dan eisen de potjes meer op dan er op de rekening staat, en klopt elk potjessaldo te gunstig totdat je iets terugneemt.',
];
