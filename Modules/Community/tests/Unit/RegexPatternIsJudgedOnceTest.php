<?php

declare(strict_types=1);

use Modules\Community\Public\Services\CorpusPatternMatcher;
use Psr\Log\AbstractLogger;

// The length cap and the compile probe read the corpus pattern alone, so an
// import that scans thousands of descriptions ran them thousands of times and
// logged the same bad row once per description. The warning count is the
// visible half of that work, which is what makes it testable.
function countingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

/** @return list<string> */
function descriptions(int $count): array
{
    $descriptions = [];
    for ($i = 0; $i < $count; $i++) {
        $descriptions[] = "CARD PAYMENT MERCHANT {$i} AMSTERDAM";
    }

    return $descriptions;
}

it('judges a malformed corpus regex once however many descriptions it is scanned against', function (): void {
    $logger = countingLogger();
    $matcher = new CorpusPatternMatcher($logger);

    foreach (descriptions(25) as $description) {
        expect($matcher->matches('regex:[unterminated', $description))->toBeFalse();
    }

    expect($logger->messages)->toHaveCount(1)
        ->and($logger->messages[0])->toContain('invalid regex pattern');
});

it('judges an over-long corpus regex once however many descriptions it is scanned against', function (): void {
    $logger = countingLogger();
    $matcher = new CorpusPatternMatcher($logger);
    $pattern = 'regex:'.str_repeat('a', CorpusPatternMatcher::MAX_REGEX_BODY_LENGTH + 1);

    foreach (descriptions(25) as $description) {
        expect($matcher->matches($pattern, $description))->toBeFalse();
    }

    expect($logger->messages)->toHaveCount(1)
        ->and($logger->messages[0])->toContain('exceeds the length cap');
});

// Two patterns that differ only past the delimiter escape have to keep their own
// verdicts: a memo keyed on anything coarser would hand one row's answer to the
// other.
it('keeps a usable regex answering per pattern once the verdict is held', function (): void {
    $matcher = new CorpusPatternMatcher(countingLogger());

    expect($matcher->matches('regex:^ALBERT HEIJN \d+$', 'ALBERT HEIJN 1234'))->toBeTrue()
        ->and($matcher->matches('regex:^ALBERT HEIJN \d+$', 'MIJN ALBERT HEIJN 1234'))->toBeFalse()
        ->and($matcher->matches('regex:^ALBERT HEIJN \d+$', 'ALBERT HEIJN 9'))->toBeTrue()
        ->and($matcher->matches('regex:[unterminated', 'ALBERT HEIJN 1234'))->toBeFalse()
        ->and($matcher->matches('regex:^ALBERT HEIJN \d+$', 'ALBERT HEIJN 77'))->toBeTrue()
        ->and($matcher->matches('regex:RUNDFUNK|ARD ZDF', 'BEITRAGSSERVICE ARD ZDF DEUTSCHLANDRADIO'))->toBeTrue()
        ->and($matcher->matches('regex:RUNDFUNK|ARD ZDF', 'ALBERT HEIJN 1234'))->toBeFalse();
});

it('still bounds a catastrophic-backtracking regex once its verdict is held', function (): void {
    $matcher = new CorpusPatternMatcher(countingLogger());
    $start = microtime(true);

    foreach (descriptions(5) as $description) {
        expect($matcher->matches('regex:(a+)+$', str_repeat('a', 40).'!'.$description))->toBeFalse();
    }

    expect(microtime(true) - $start)->toBeLessThan(5.0);
});
