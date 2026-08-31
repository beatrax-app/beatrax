<?php

declare(strict_types=1);

use Modules\Core\Public\Support\SafeTrace;

// The bundled ini now carries zend.exception_ignore_args=1, and these throw
// with it forced back Off: the ini is defence in depth, and the runtimes it is
// written into -- desktop, mobile, CI -- do not share one php.ini. The code has
// to hold on the machine where the directive never arrived.
beforeEach(function (): void {
    $this->restoreExceptionArgs = (string) ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
});

afterEach(function (): void {
    ini_set('zend.exception_ignore_args', $this->restoreExceptionArgs);
});

function safeTraceParseLine(string $row): never
{
    throw new RuntimeException('unparseable: '.substr($row, 0, 4));
}

function safeTraceThrownFromAParse(string $row): Throwable
{
    try {
        safeTraceParseLine($row);
    } catch (Throwable $e) {
        return $e;
    }
}

// getTraceAsString() renders the first 15 characters of every string argument,
// and on a parse frame that argument is a row of the reader's bank statement --
// landing in the 0644 daily log beside the SafeExceptionContext that exists to
// keep it out.
it('keeps a statement row out of the line the frame is written as', function (): void {
    $row = '2026-08-01,-42.50,ACME CORP,NL91ABNA0417164300';

    $capped = SafeTrace::cap(safeTraceThrownFromAParse($row), base_path());

    expect($capped)
        ->toContain('safeTraceParseLine()')
        ->not->toContain('2026-08-01')
        ->not->toContain('42.50');
});

it('writes one frame for two rows that share a call site and differ only in their data', function (): void {
    $one = explode("\n", SafeTrace::cap(safeTraceThrownFromAParse('2026-08-01,-42.50,ACME'), base_path()))[0];
    $other = explode("\n", SafeTrace::cap(safeTraceThrownFromAParse('1999-01-01,+9.99,OTHER'), base_path()))[0];

    expect($one)->toBe($other);
});

it('still names the frame, its file and its line', function (): void {
    $capped = SafeTrace::cap(safeTraceThrownFromAParse('x'), base_path(), maxLines: 200);

    expect($capped)
        ->toStartWith('#0 ')
        ->toContain('ACappedTraceNamesTheFrameNeverItsArgumentsTest.php(')
        ->toEndWith('{main}');
});

// The path rewrite this class was written for, on the frame's own file rather
// than on a rendered string.
it('rewrites an absolute project path to a relative one', function (): void {
    $capped = SafeTrace::cap(safeTraceThrownFromAParse('x'), base_path());

    expect($capped)
        ->not->toContain(base_path().'/')
        ->toContain('Modules/Core/tests/Unit/');
});

it('caps a deep trace and says how many frames it dropped', function (): void {
    $deep = static function (int $depth) use (&$deep): never {
        if ($depth > 0) {
            $deep($depth - 1);
        }

        throw new RuntimeException('deep');
    };

    try {
        $deep(40);
        $thrown = new RuntimeException('unreachable');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $capped = SafeTrace::cap($thrown, base_path(), maxLines: 5);

    expect(substr_count($capped, "\n"))->toBe(5)
        ->and($capped)->toContain('… +');
});
