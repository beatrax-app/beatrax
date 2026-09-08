<?php

declare(strict_types=1);

use Modules\Community\Internal\Shell\UnopenedUrl;
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

it('keeps the statement description out of the fallback opener log line', function (): void {
    $logger = recordingLogger();

    expect((new UnopenedUrl($logger))->open(SUGGEST_URL))->toBeFalse();

    expect($logger->lines)->toHaveCount(1);
    expect($logger->lines[0][1]['url'])->toBe('https://github.com/nightworks/beatrax-community/compare/main...suggest-888f749d85409ace');
    expect(json_encode($logger->lines))->not->toContain('JANSEN');
});

it('keeps it out of the action log line too', function (): void {
    $logger = recordingLogger();
    $opener = new UnopenedUrl(recordingLogger());

    (new OpenExternalUrlAction($opener, $logger))(SUGGEST_URL);

    expect(json_encode($logger->lines))->not->toContain('JANSEN');
});

it('accepts an allow-listed host written in capitals', function (): void {
    $logger = recordingLogger();
    $opener = new UnopenedUrl(recordingLogger());

    (new OpenExternalUrlAction($opener, $logger))('https://GITHUB.COM/nightworks/beatrax-community');

    expect($logger->lines)->toHaveCount(1);
});
