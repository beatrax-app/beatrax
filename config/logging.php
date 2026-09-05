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

    // Only the channels that write to the local log file tap
    // PushRedactProcessor, which redacts the OAuth scrub set, Bearer tokens
    // and JWTs before the formatter reaches disk.
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

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            'tap' => [],
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
            'tap' => [],
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
            'tap' => [],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        // No 'permission' here on purpose: LogManager::createEmergencyLogger()
        // constructs its StreamHandler with only a path and a level, so the key
        // would read as a decision and change nothing. EnsurePrivateLogFiles
        // narrows the whole logs directory instead, which is what covers this.
        'emergency' => [
            'path' => UserDataPathService::logsFile(),
        ],

    ],

];
