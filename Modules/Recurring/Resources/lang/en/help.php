<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'A statement is a flat list of dates and amounts, and nothing in it says which rows are the same standing commitment. Beatrax groups rows by who was paid, drops the amounts that are out of line with the rest of the group, and suggests a series only once the gaps between them settle into a steady weekly, monthly, quarterly or yearly rhythm — anything less regular is never suggested at all. It reads back only as far as “:setting” in Settings, which starts at the shortest span it can work with, so a yearly bill stays out of view until you widen that. Nothing here is applied to your data until you approve it.',
];
