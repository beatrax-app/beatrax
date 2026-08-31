<?php

declare(strict_types=1);

return [

    'reconciled_lock' => 'This transaction is reconciled. Un-reconcile it to make changes.',
    'tagged' => 'Tagged as tax-deductible.',
    'untagged' => 'Tax tag removed.',
    'batch_none_reconciled' => 'Nothing tagged — those transactions are reconciled. Un-reconcile them to make changes.',
    'batch_tagged' => 'Tagged :count more transaction.|Tagged :count more transactions.',

    'errors' => [
        'name_empty' => 'Category name cannot be empty.',
        'name_duplicate' => 'A category with this name already exists.',
        'category_not_saved' => 'That category could not be saved. Try again.',
        'tag_refused' => 'That tag could not be saved. Close the picker and try again.',
    ],
];
