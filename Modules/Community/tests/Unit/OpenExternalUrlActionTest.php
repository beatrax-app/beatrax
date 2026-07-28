<?php

declare(strict_types=1);

use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Native\Desktop\Contracts\Shell as ShellContract;
use Native\Desktop\Fakes\ShellFake;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Tiny PSR-3 logger recorder used to confirm the action emits an
 * `info` line on the success branch.
 */
function makeRecorderLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
}

beforeEach(function (): void {
    $this->shell = new ShellFake;
    $this->logger = makeRecorderLogger();
    $this->app->instance(ShellContract::class, $this->shell);
    $this->app->instance(LoggerInterface::class, $this->logger);
});

it('launches the system browser for a valid https://github.com URL', function (): void {
    /** @var OpenExternalUrlAction $action */
    $action = $this->app->make(OpenExternalUrlAction::class);

    $action('https://github.com/beatrax-app/beatrax/compare/main...suggest-abc?expand=1&body=foo');

    expect($this->shell->openExternalCalls)->toContain(
        'https://github.com/beatrax-app/beatrax/compare/main...suggest-abc?expand=1&body=foo'
    );
});

it('rejects an http:// URL', function (): void {
    /** @var OpenExternalUrlAction $action */
    $action = $this->app->make(OpenExternalUrlAction::class);

    expect(fn () => $action('http://github.com/beatrax-app/beatrax/compare/main'))
        ->toThrow(InvalidArgumentException::class);
    expect($this->shell->openExternalCalls)->toBe([]);
});

it('rejects an https URL with a non-allow-listed host', function (): void {
    /** @var OpenExternalUrlAction $action */
    $action = $this->app->make(OpenExternalUrlAction::class);

    expect(fn () => $action('https://evil.example.com/path'))
        ->toThrow(InvalidArgumentException::class);
    expect($this->shell->openExternalCalls)->toBe([]);
});

it('rejects a javascript: URL', function (): void {
    /** @var OpenExternalUrlAction $action */
    $action = $this->app->make(OpenExternalUrlAction::class);

    expect(fn () => $action("javascript:alert('XSS')"))
        ->toThrow(InvalidArgumentException::class);
    expect($this->shell->openExternalCalls)->toBe([]);
});
