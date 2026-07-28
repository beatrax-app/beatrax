<?php

declare(strict_types=1);

use Modules\Chains\Public\Exceptions\CardStatementNotFoundException;
use Modules\Chains\Public\Exceptions\ChainLinkNotDismissableException;
use Modules\Chains\Public\Exceptions\EvidenceEncodingFailedException;

/*
 * The three failure types that replaced bare RuntimeException throws.
 *
 * Each carries the identifiers a caller needs to act on the failure
 * without parsing the message, which is the reason the types exist.
 * EvidenceEncodingFailedException is covered here rather than through
 * its call sites because reaching it needs a payload json_encode
 * cannot represent, which the insert paths cannot construct.
 */

it('names the chain link a dismissal was refused for', function (): void {
    $e = new ChainLinkNotDismissableException(4711);

    expect($e->chainLinkId)->toBe(4711)
        ->and($e->getMessage())->toContain('4711')
        ->and($e->getMessage())->toContain('confirm/reject')
        ->and($e)->toBeInstanceOf(RuntimeException::class);
});

it('names the statement and user a lookup failed for', function (): void {
    $e = new CardStatementNotFoundException(88, 12);

    expect($e->statementId)->toBe(88)
        ->and($e->userId)->toBe(12)
        ->and($e->getMessage())->toBe('card_statement 88 not found for user 12')
        ->and($e)->toBeInstanceOf(RuntimeException::class);
});

it('names the call site whose evidence would not encode', function (): void {
    $e = new EvidenceEncodingFailedException('hint event');

    expect($e->context)->toBe('hint event')
        ->and($e->getMessage())->toContain('chain_links.evidence')
        ->and($e->getMessage())->toContain('hint event')
        ->and($e)->toBeInstanceOf(RuntimeException::class);
});
