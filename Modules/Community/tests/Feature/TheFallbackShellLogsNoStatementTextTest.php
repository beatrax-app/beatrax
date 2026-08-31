<?php

declare(strict_types=1);

use Modules\Community\Internal\Shell\NoOpShell;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Psr\Log\AbstractLogger;

// transactions.description is encrypted at rest so a statement line never
// reaches the disk in the clear. The suggest-mapping compare URL carries it
// URL-encoded in the query string, and storage/logs is rendered by /dev/logs.
function recordingLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $lines = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->lines[] = [(string) $message, $context];
        }
    };
}

const SUGGEST_URL = 'https://github.com/nightworks/beatrax-community/compare/main...suggest-888f749d85409ace'
    .'?expand=1&body=entries%3A%0A%20%20-%20pattern%3A%20%22SEPA%20IDEAL%20BCA%2ABOLDKING-37261%20DR%20J%20JANSEN%22';

it('keeps the statement description out of the fallback shell log line', function (): void {
    $logger = recordingLogger();

    (new NoOpShell($logger))->openExternal(SUGGEST_URL);

    expect($logger->lines)->toHaveCount(1);
    expect($logger->lines[0][1]['url'])->toBe('https://github.com/nightworks/beatrax-community/compare/main...suggest-888f749d85409ace');
    expect(json_encode($logger->lines))->not->toContain('JANSEN');
});

it('keeps it out of the real shell log line too', function (): void {
    $logger = recordingLogger();
    $shell = new NoOpShell(recordingLogger());

    (new OpenExternalUrlAction($shell, $logger))(SUGGEST_URL);

    expect(json_encode($logger->lines))->not->toContain('JANSEN');
});

it('accepts an allow-listed host written in capitals', function (): void {
    $logger = recordingLogger();
    $shell = new NoOpShell(recordingLogger());

    (new OpenExternalUrlAction($shell, $logger))('https://GITHUB.COM/nightworks/beatrax-community');

    expect($logger->lines)->toHaveCount(1);
});
