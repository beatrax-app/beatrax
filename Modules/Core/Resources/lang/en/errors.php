<?php

declare(strict_types=1);

return [
    'back' => 'Back to Beatrax',

    'not_saved' => 'Nothing was saved. Your data is unchanged — try again.',

    'no_longer_here' => 'That is no longer here.',

    '404' => [
        'title' => 'This page does not exist',
        'body' => 'The link may be old, or the page may have been renamed. Nothing is wrong with your data.',
    ],
    '4xx' => [
        'title' => 'This request cannot be handled',
        'body' => 'The page was opened in a way it does not expect. Your data is unchanged.',
    ],

    '419' => [
        'title' => 'Your session expired',
        'body' => 'You were away long enough for the page to go stale. Open Beatrax again and carry on.',
    ],

    '500' => [
        'title' => 'Something went wrong',
        'body' => 'The problem has been written to this device\'s log. Your data was not changed.',
    ],

    '503' => [
        'title' => 'Beatrax is briefly unavailable',
        'body' => 'An update or maintenance task is finishing. Try again in a moment.',
    ],
];
