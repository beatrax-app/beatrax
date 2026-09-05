<?php

declare(strict_types=1);

use Modules\Core\Public\Support\NamesTheCellItRefused;
use Modules\Core\Public\Support\RefusedCell;
use Modules\Core\Public\Support\SafeExceptionContext;

// describe() strips every message because it cannot know which of them quotes a
// row. A cell is the one diagnostic worth keeping, so it travels beside that
// strip rather than through it — and it is still a figure out of the reader's
// own file, so the log gets a bounded excerpt of it and never the whole cell.

it('hands the log the file, the column and the cell, unaltered when the cell is short', function (): void {
    $context = (new RefusedCell('Register.csv', 'Outflow', 'twelve euros'))->toLogContext();

    expect($context)->toBe([
        'refused_file' => 'Register.csv',
        'refused_column' => 'Outflow',
        'refused_value' => 'twelve euros',
        'refused_value_bytes' => 12,
    ]);
});

it('caps the cell and keeps its true length, so a mis-quoted memo is bounded but legible', function (): void {
    $memo = str_repeat('a', 400);

    $context = (new RefusedCell('Register.csv', 'Outflow', $memo))->toLogContext();

    expect($context['refused_value'])->toBe(str_repeat('a', RefusedCell::MAX_VALUE_BYTES).'…')
        ->and($context['refused_value_bytes'])->toBe(400);
});

it('keeps the cell on one line, whatever the file put in it', function (): void {
    $context = (new RefusedCell('Register.csv', 'Memo', "line one\r\nline two\ttabbed"))->toLogContext();

    expect($context['refused_value'])->toBe('line one line two tabbed');
});

it('writes an unreadable byte sequence as something the log encoder can carry', function (): void {
    $context = (new RefusedCell('Register.csv', 'Payee', "caf\xE9 bar"))->toLogContext();

    expect(mb_check_encoding($context['refused_value'], 'UTF-8'))->toBeTrue()
        ->and($context['refused_value_bytes'])->toBe(8);
});

it('answers nothing for an exception that promised no cell', function (): void {
    expect(SafeExceptionContext::refusedCell(new RuntimeException('Outflow value: twelve euros')))->toBe([]);
});

it('answers nothing for a promise that turned out to hold no cell', function (): void {
    $promised = new class extends RuntimeException implements NamesTheCellItRefused
    {
        public function refusedCell(): ?RefusedCell
        {
            return null;
        }
    };

    expect(SafeExceptionContext::refusedCell($promised))->toBe([]);
});

// The strip is the behaviour the gap was made of, and it is deliberate: the
// cell now reaches the log beside it, and describe() must go on answering the
// class and the SQLSTATE alone for every exception a broad catch can receive.
it('leaves describe() naming the class and nothing the exception quoted', function (): void {
    $refusal = new class('Register.csv Outflow value: twelve euros') extends RuntimeException implements NamesTheCellItRefused
    {
        public function refusedCell(): ?RefusedCell
        {
            return new RefusedCell('Register.csv', 'Outflow', 'twelve euros');
        }
    };

    $described = SafeExceptionContext::describe($refusal);

    expect(array_keys($described))->toBe(['reason', 'sqlstate'])
        ->and($described['reason'])->not->toContain('twelve euros')
        ->and($described['sqlstate'])->toBe('');
});
