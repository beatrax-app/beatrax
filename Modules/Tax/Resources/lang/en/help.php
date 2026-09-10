<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/tax/tax-year-resolution.md#summaryforuser-totalminor-is-the-deductions-total-only */
    'total_deductions' => 'What your tagged transactions add up to for this tax year — deductions only. Anything tagged as income is counted apart under “:income” and is never subtracted here, so this is a total of what you are claiming rather than a net position. A row counts towards the tax year of its own date rather than the year you tagged it in, and the “:yearcol” column below is where one can be moved to a different year.',
];
