<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'L’argent déjà arrivé qui n’a pas encore d’enveloppe : les revenus de cette période, plus ce qui est resté non attribué à la période précédente, moins tout ce qui est attribué ci-dessous. Ramenez-le à zéro et plus rien n’est laissé sans plan. En dessous de zéro, vous avez attribué plus que ce qui est réellement rentré : reprenez une part dans une enveloppe ou attendez la prochaine paie.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Ce qui arrive à une enveloppe qui a dépensé plus qu’elle ne contient, une fois la période terminée. Avec « :reduce », le déficit est retiré d’emblée de ce que vous aurez à répartir la période suivante, et l’enveloppe elle-même repart de zéro. Avec « :carry », le déficit reste là où il est né : cette enveloppe démarre sous zéro et doit être renflouée avant de payer quoi que ce soit, et le reste du plan n’est pas touché.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Ce que cette enveloppe peut encore payer : « :assigned » pour cette période, plus « :carried », plus ou moins « :moved », moins « :spent » — les quatre colonnes à gauche. Ce n’est pas un solde bancaire : plusieurs enveloppes puisent dans le même compte, et ce chiffre ne parle que de celle-ci. En dessous de zéro, l’enveloppe a déjà dépensé plus qu’elle ne contient, et la fin de la période décide du sort de ce déficit.',
];
