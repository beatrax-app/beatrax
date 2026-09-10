<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'What confirming will do with each row. “:new” is added to your ledger; “:duplicate” is already there from an earlier import and is skipped, so re-importing a statement that overlaps one you already have costs nothing; “:enriched” matches a row you have and fills in detail the first file did not carry, without adding a second copy. Nothing on this screen has touched your ledger yet — “:confirm” is the moment it does.',
];
