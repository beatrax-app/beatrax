<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Money that has arrived and has no envelope yet: this period’s income, plus anything you left unassigned last period, minus everything assigned below. Bring it to zero and nothing is left unplanned. Below zero means you have assigned more than has actually come in — take some back out of an envelope, or wait for the next payday.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'What happens to an envelope that has spent more than it holds, once the period ends. Choose “:reduce” and the shortfall comes off the top of what you have left to plan with next period, while the envelope itself starts again at zero. Choose “:carry” and the shortfall stays where it happened: that envelope opens below zero and has to be filled back up before it pays for anything, and nothing else in the plan moves.',
];
