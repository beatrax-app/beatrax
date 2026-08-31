<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'L’argent déjà arrivé qui n’a pas encore d’enveloppe : les revenus de cette période, plus ce qui est resté non attribué à la période précédente, moins tout ce qui est attribué ci-dessous. Ramenez-le à zéro et plus rien n’est laissé sans plan. En dessous de zéro, vous avez attribué plus que ce qui est réellement rentré : reprenez une part dans une enveloppe ou attendez la prochaine paie.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Ce qui arrive à une enveloppe qui a dépensé plus qu’elle ne contient, une fois la période terminée. Avec « :reduce », le déficit est retiré d’emblée de ce que vous aurez à répartir la période suivante, et l’enveloppe elle-même repart de zéro. Avec « :carry », le déficit reste là où il est né : cette enveloppe démarre sous zéro et doit être renflouée avant de payer quoi que ce soit, et le reste du plan n’est pas touché.',
];
