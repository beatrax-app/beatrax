<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    // `daily`, not `stack`: the Dev Console tailer reads "today / yesterday /
    // earlier" straight off the rolling filenames.
    'default' => env('LOG_CHANNEL', 'daily'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    // Every channel that can write anywhere taps PushRedactProcessor, which
    // redacts the OAuth scrub set, Bearer tokens and JWTs before the formatter
    // runs. It used to be the three file channels, which made redaction a
    // property of a deployment shape: LOG_CHANNEL=stderr shipped unredacted.
    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'daily')),
            'ignore_exceptions' => false,
            'tap' => [PushRedactProcessor::class],
        ],

        'single' => [
            'driver' => 'single',
            'path' => UserDataPathService::logsFile(),
            'level' => env('LOG_LEVEL', 'debug'),
            'permission' => SecretFileMode::FILE,
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => UserDataPathService::logsFile(),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'permission' => SecretFileMode::FILE,
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        // Not ours: the framework's own config supplies this channel for any key
        // config/logging.php leaves out, so it was reachable, writing to
        // storage/logs, and tapping nothing. Declared here to carry the tap,
        // and pointed at the same file `daily` uses with the same permission.
        'monthly' => [
            'driver' => 'monthly',
            'path' => UserDataPathService::logsFile(),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'permission' => SecretFileMode::FILE,
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [PushRedactProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [PushRedactProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [PushRedactProcessor::class],
        ],

        // The two channels with no 'tap' key, each for its own reason. This one
        // discards by construction, and NullHandler is not processable anyway,
        // so the tap would be resolved and then skipped.
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        // No 'permission' and no 'tap' here on purpose: createEmergencyLogger()
        // builds its StreamHandler from a path and a level and never calls
        // tap(), so both keys would read as a decision and change nothing.
        // EnsurePrivateLogFiles narrows the whole logs directory instead.
        'emergency' => [
            'path' => UserDataPathService::logsFile(),
        ],

    ],

];
