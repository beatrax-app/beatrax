<?php

declare(strict_types=1);

return [
    'heading' => 'Logs',
    'subtitle' => "Live tail of the current day's Laravel log file with belt-and-braces on-write + on-stream redaction.",
    'truncate' => 'Truncate',
    'truncate_confirm' => "Truncate today's log file? This cannot be undone.",
    'truncate_title' => "Empty today's log file (preserves the inode so the tailer resumes cleanly)",
    'filters_aria' => 'Log filters',
    'severity_aria' => 'Severity filter',
    'channel_placeholder' => 'Channel filter…',
    'channel_aria' => 'Channel filter',
    'contains_placeholder' => 'Search visible…',
    'contains_aria' => 'Contains filter',
    'pause' => 'Pause',
    'resume' => 'Resume',
    'waiting' => 'Waiting for log lines…',
    'copy' => 'Copy',
    'copy_title' => 'Copy full entry',
    'copy_title_copied' => 'Copied',
    'copy_aria' => 'Copy log entry',
    'copy_aria_copied' => 'Copied to clipboard',
    'dismiss' => 'Dismiss',
    'dismiss_title' => 'Dismiss from view (does not modify the log file)',
    'dismiss_aria' => 'Dismiss log entry from view',
    'totals' => [
        'showing' => 'Showing :shown of :count line received (buffer cap :cap)|Showing :shown of :count lines received (buffer cap :cap)',
        'lines_today' => ':count line today|:count lines today',
        'lines_today_capped' => 'over :count line today|over :count lines today',
        'today' => 'today',
        'all_files' => ':size across :count daily file|:size across :count daily files',
    ],

    'status' => [
        'poll_interrupted' => 'Log poll interrupted. Retrying…',
        'paused' => 'Paused.',
        'copy_failed_prefix' => 'Copy failed: ',
        'clipboard_unavailable' => 'clipboard unavailable',
    ],

    'toast' => [
        'truncated' => 'Log truncated — freed :size.',
        'nothing' => 'Nothing to truncate.',
    ],
];
