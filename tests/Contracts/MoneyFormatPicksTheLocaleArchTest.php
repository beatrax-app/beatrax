<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-locale-argument-passed-to-moneyformat
 */

/** @return list<string> repo-relative PHP and Blade files under Modules/, app/ and resources/ */
function moneyFormatRenderingFiles(): array
{
    $files = [];

    foreach (['Modules', 'app', 'resources'] as $root) {
        $absolute = base_path($root);
        if (! is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<array{args: string, line: int}> every ->format(...) call, argument text intact
 */
function moneyFormatCalls(string $source): array
{
    $calls = [];
    $offset = 0;

    while (($start = strpos($source, '->format(', $offset)) !== false) {
        $cursor = $start + strlen('->format(');
        $depth = 1;

        while ($depth > 0 && $cursor < strlen($source)) {
            $depth += match ($source[$cursor]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $cursor++;
        }

        $calls[] = [
            'args' => substr($source, $start + strlen('->format('), $cursor - $start - strlen('->format(') - 1),
            'line' => substr_count($source, "\n", 0, $start) + 1,
        ];
        $offset = $cursor;
    }

    return $calls;
}

it('hands format() no locale to override the one the currency implies', function (): void {
    $offenders = [];

    foreach (moneyFormatRenderingFiles() as $file) {
        $source = (string) file_get_contents(base_path($file));
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $source) ?? $source;

        foreach (moneyFormatCalls($stripped) as $call) {
            // Any locale anywhere in the argument list, not just a bare
            // literal — a computed one is the shape that survived the rule's
            // first pass.
            if (preg_match('/[\'"]([a-z]{2}[_-][A-Z]{2})[\'"]/', $call['args'], $match) !== 1) {
                continue;
            }

            $offenders[] = $file.':'.$call['line'].' — format(… '.$match[1].' …)';
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A hardcoded locale renders a foreign currency in someone else's\n".
        "separators — nl_NL turns \$1,245.67 into US\$ -1.245,67. Drop the\n".
        "argument: Money::format() already resolves nl_NL for EUR and en_US for\n".
        "everything else, on every runtime. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('gives Money::format() no locale parameter to pass in the first place', function (): void {
    $signature = new ReflectionMethod(Modules\Ledger\Public\ValueObjects\Money::class, 'format');

    expect($signature->getNumberOfParameters())->toBe(
        0,
        'Money::format() decides the locale from the currency. A parameter here '.
        'is an invitation to override that per call site, which is exactly how '.
        'thirty of them came to render USD with Dutch separators.',
    );
});
