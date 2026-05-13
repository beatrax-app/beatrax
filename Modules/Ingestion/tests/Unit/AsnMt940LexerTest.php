<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Lexer;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

beforeEach(function (): void {
    $this->lexer = $this->app->make(AsnMt940Lexer::class);
});

it('yields one (tag, content) pair per :NN: line on a simple file', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n:28C:1/1\n";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens)->toBe([
        ['20', 'STMT-001'],
        ['25', 'NL57ASNB0123456789'],
        ['28C', '1/1'],
    ]);
})->group('phase-2');

it('appends continuation lines to the previous tag buffer with newline preserved', function (): void {
    $body = ":86:GVC005?20EREF+NOTPROVIDED?32STARBUCKS\nAMSTERDAM?31NL00BANK0000000001\n";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens)->toHaveCount(1);
    expect($tokens[0][0])->toBe('86');
    expect($tokens[0][1])->toContain('AMSTERDAM');
    expect($tokens[0][1])->toContain("\n");
})->group('phase-2');

it('handles CRLF line endings by stripping the carriage return at line-split', function (): void {
    $body = ":20:STMT-001\r\n:25:NL57ASNB0123456789\r\n";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens[0][1])->toBe('STMT-001');
    expect($tokens[1][1])->toBe('NL57ASNB0123456789');
})->group('phase-2');

it('flushes the current tag on the lone EOM marker', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n-\n";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens)->toHaveCount(2);
})->group('phase-2');

it('flushes the last tag at EOF even without a final EOM marker', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens)->toHaveCount(2);
    expect($tokens[1][1])->toBe('NL57ASNB0123456789');
})->group('phase-2');

it('strips the SWIFT block-1 envelope and tokenizes block-4 contents', function (): void {
    $body = '{1:F01ASNBNL21XXXX0000000000}{2:O9400000000ASNBNL21XXXX00000000000000000000N}{4:'
        ."\n:20:STMT-001\n:25:NL57ASNB0123456789\n-\n}";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);

    expect($tokens)->toHaveCount(2);
    expect($tokens[0])->toBe(['20', 'STMT-001']);
})->group('phase-2');

it('yields multi-statement files with all tags from all statements in stream order', function (): void {
    $body = ":20:STMT-A\n:25:NL57ASNB0123456789\n:62F:C260430EUR1000,00\n-\n"
        .":20:STMT-B\n:25:NL57ASNB0123456789\n:62F:C260531EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $tokens = iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);
    $tags = array_map(static fn (array $t): string => $t[0], $tokens);

    expect($tags)->toBe(['20', '25', '62F', '20', '25', '62F']);
})->group('phase-2');

it('aborts when total line count exceeds the line cap', function (): void {
    $body = str_repeat(":20:LINE\n", 100_001);
    $tmp = writeMt940Temp($body);

    expect(function () use ($tmp): void {
        iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);
    })->toThrow(InvalidAmountException::class, 'line limit');
})->group('phase-2');

it('aborts when a single tag buffer exceeds the buffer cap', function (): void {
    $body = ':86:'.str_repeat('A', 17_000)."\n";
    $tmp = writeMt940Temp($body);

    expect(function () use ($tmp): void {
        iterator_to_array($this->lexer->tokenize($tmp), preserve_keys: false);
    })->toThrow(InvalidAmountException::class, 'buffer limit');
})->group('phase-2');
