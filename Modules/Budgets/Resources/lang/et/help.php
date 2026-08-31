<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Raha, mis on juba kohale jõudnud ja millel pole veel ümbrikku: selle perioodi sissetulek, pluss eelmisest perioodist jaotamata jäänu, miinus kõik allpool jaotatu. Vii see nullini, siis ei jää midagi plaanita. Alla nulli tähendab, et oled jaotanud rohkem, kui tegelikult sisse tuli — võta midagi ümbrikust tagasi või oota järgmist palgapäeva.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Mis juhtub ümbrikuga, mis on kulutanud rohkem, kui tal on, kui periood lõpeb. Valikuga „:reduce“ arvatakse puudujääk esimesena maha sellest, mida sul on järgmisel perioodil jaotada, ja ümbrik ise alustab uuesti nullist. Valikuga „:carry“ jääb puudujääk sinna, kus see tekkis: see ümbrik avaneb miinuses ja tuleb enne mis tahes maksmist uuesti täita, ülejäänud plaan aga ei liigu.',
];
