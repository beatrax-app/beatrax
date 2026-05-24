<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Baseline regression guard for the Monolog
 * RedactSecretsProcessor + PushRedactProcessor +
 * config/logging.php tap-slot wiring.
 *
 * Covers the Bearer + JWT scrub layer (the OAuthScrubSet layer
 * has its own coverage in OAuthScrubSetBustTest). The end-to-end
 * on-write redaction test uses an ephemeral per-test channel built
 * via LogManager::build() with the same tap array so the test
 * does not race the real shared laravel-{date}.log under parallel
 * Pest.
 */

it('RedactSecretsProcessor replaces Authorization: Bearer + standalone JWT in the message', function (): void {
    $processor = new RedactSecretsProcessor;

    // Each JWT segment is >=20 chars to match the strict JWT regex.
    $bearer = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0AAA.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $standaloneJwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI5OTk5OTk5OTk5In0BBB.aaaaaaaaaaaaaaaaaaaaaaaaa';
    $message = "got token: {$bearer} and standalone: {$standaloneJwt}";

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: [],
        extra: [],
    );

    $out = $processor($record);

    expect($out->message)->toContain('Authorization: Bearer [REDACTED]');
    expect($out->message)->toContain('[JWT_REDACTED]');
    expect($out->message)->not->toContain('SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c');
    expect($out->message)->not->toContain('aaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('RedactSecretsProcessor recursively scrubs nested context arrays', function (): void {
    $processor = new RedactSecretsProcessor;

    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI4ODg4ODg4ODg4In0CCC.bbbbbbbbbbbbbbbbbbbbbbbbb';
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: 'see context',
        context: [
            'headers' => ['raw' => 'Authorization: Bearer abcdef-ghij-klmno'],
            'nested' => [$jwt],
            'untouched_int' => 42,
        ],
        extra: ['extrastring' => 'Authorization: Bearer leakytoken'],
    );

    $out = $processor($record);

    expect($out->context['headers']['raw'])->toBe('Authorization: Bearer [REDACTED]');
    expect($out->context['nested'][0])->toBe('[JWT_REDACTED]');
    expect($out->context['untouched_int'])->toBe(42);
    expect($out->extra['extrastring'])->toContain('Authorization: Bearer [REDACTED]');
});

it('config/logging.php has PushRedactProcessor in the tap slot for stack, single, and daily channels', function (): void {
    $stack = config('logging.channels.stack.tap', []);
    $single = config('logging.channels.single.tap', []);
    $daily = config('logging.channels.daily.tap', []);

    expect($stack)->toContain(PushRedactProcessor::class);
    expect($single)->toContain(PushRedactProcessor::class);
    expect($daily)->toContain(PushRedactProcessor::class);
});

it('end-to-end: logger() writes redacted Bearer to disk via the tapped channel', function (): void {
    $tmpPath = tempnam(sys_get_temp_dir(), 'redact-test-').'.log';

    /** @var LogManager $manager */
    $manager = app(LogManager::class);

    $channel = $manager->build([
        'driver' => 'single',
        'path' => $tmpPath,
        'level' => 'debug',
    ]);

    // LogManager::build() bypasses configurationFor()-based tap resolution
    // for ondemand channels, so we apply the tap manually here — this
    // exercises the SAME PushRedactProcessor wiring that config/logging.php
    // applies for the real 'single' / 'daily' / 'stack' channels.
    (new PushRedactProcessor)($channel);

    $channel->info('event: Authorization: Bearer toplevelsecrettoken');
    // Force file flush
    foreach ($channel->getLogger()->getHandlers() as $handler) {
        if (method_exists($handler, 'close')) {
            $handler->close();
        }
    }

    $contents = (string) file_get_contents($tmpPath);
    expect($contents)->toContain('Authorization: Bearer [REDACTED]');
    expect($contents)->not->toContain('toplevelsecrettoken');

    @unlink($tmpPath);
});
