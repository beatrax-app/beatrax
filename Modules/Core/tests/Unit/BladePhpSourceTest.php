<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;

// Every case here is one `token_get_all` answers wrongly on the template it was
// given: the raw reading is asserted beside the seam's, so a green run is
// evidence the islands were read rather than evidence nothing was there.

/** @return list<array{name: string, line: int}> */
function bladePhpSourceCalls(string $source): array
{
    $found = [];
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_STRING && ($tokens[$index + 1] ?? '') === '(') {
            $found[] = ['name' => $token[1], 'line' => $token[2]];
        }
    }

    return $found;
}

it('reads the PHP in each construct the tokeniser cannot enter', function (string $template): void {
    expect(bladePhpSourceCalls($template))->toBe([]);

    expect(bladePhpSourceCalls(BladePhpSource::of($template)))->toBe([['name' => 'trim', 'line' => 1]]);
})->with([
    'an @php block' => ["@php trim(\$x); @endphp\n"],
    'an @php expression' => ["@php(trim(\$x))\n"],
    'an escaped echo' => ["<b>{{ trim(\$x) }}</b>\n"],
    'a raw echo' => ["<b>{!! trim(\$x) !!}</b>\n"],
    'a triple echo' => ["<b>{{{ trim(\$x) }}}</b>\n"],
    'a directive argument' => ["@if (trim(\$x))y@endif\n"],
    'a directive argument holding a fat arrow' => ["<i @class(['on' => trim(\$x)])></i>\n"],
]);

// The two constructs the raw reading already handles, kept so a rewrite cannot
// drop them on the way to the ones it could not see.
it('leaves a literal PHP tag reading exactly as it did', function (string $template): void {
    expect(bladePhpSourceCalls($template))->toBe([['name' => 'trim', 'line' => 1]])
        ->and(bladePhpSourceCalls(BladePhpSource::of($template)))->toBe([['name' => 'trim', 'line' => 1]]);
})->with([
    'a full tag' => ["<b><?php trim(\$x); ?></b>\n"],
    'a short echo tag' => ["<b><?= trim(\$x) ?></b>\n"],
]);

it('reads nothing out of the constructs Blade prints as text', function (string $template): void {
    expect(bladePhpSourceCalls(BladePhpSource::of($template)))->toBe([]);
})->with([
    'a Blade comment' => ["{{-- trim(\$x) --}}\n"],
    'a verbatim body' => ["@verbatim {{ trim(\$x) }} @endverbatim\n"],
    'an escaped echo' => ["@{{ trim(\$x) }}\n"],
    'an escaped directive' => ["@@if (trim(\$x))\n"],
    'an Alpine handler holding an arrow function' => ["<div @click=\"() => { open = true }\"></div>\n"],
    'an email address in prose' => ["<p>write to help@example.test today</p>\n"],
]);

// A guard reports the file and the line it wants edited, so an island that
// shifted the ones under it would send every reader to the wrong place.
it('leaves every line where the template put it', function (): void {
    $template = <<<'BLADE'
        <div>
        @php
            $a = trim($x);
        @endphp
        <span>{{ strlen($a) }}</span>
        {{-- a comment
             over two lines --}}
        @if (is_string($a))<b>{{ $a }}</b>@endif
        </div>
        BLADE;

    expect(bladePhpSourceCalls(BladePhpSource::of($template)))->toBe([
        ['name' => 'trim', 'line' => 3],
        ['name' => 'strlen', 'line' => 5],
        ['name' => 'is_string', 'line' => 8],
    ]);

    expect(substr_count(BladePhpSource::of($template), "\n"))->toBe(substr_count($template, "\n"));
});

// A `{{ }}` whose expression runs over two lines keeps both, or the line count
// under it drifts by one for the rest of the file.
it('keeps the newlines inside an island as well as the ones around it', function (): void {
    $template = "<p>{{ trim(\n    \$x\n) }}</p>\n<b>{{ strlen(\$x) }}</b>\n";

    expect(bladePhpSourceCalls(BladePhpSource::of($template)))->toBe([
        ['name' => 'trim', 'line' => 1],
        ['name' => 'strlen', 'line' => 4],
    ]);
});

it('hands a PHP file back as itself and a template back as its islands', function (): void {
    $php = "<?php\n\ntrim(\$x);\n";

    expect(BladePhpSource::forPath('Modules/Core/Public/Support/Thing.php', $php))->toBe($php)
        ->and(BladePhpSource::forPath('Modules/Core/Resources/views/thing.blade.php', "<b>{{ trim(\$x) }}</b>\n"))
        ->toContain('trim($x)');
});

// An unterminated island is the file the guard most needs an answer about, and
// the answer has to be the code up to where it stopped rather than nothing.
it('reads what an unclosed island holds up to the end of the file', function (): void {
    expect(bladePhpSourceCalls(BladePhpSource::of("<b>\n@php\n    trim(\$x);\n")))
        ->toBe([['name' => 'trim', 'line' => 3]]);

    expect(bladePhpSourceCalls(BladePhpSource::of("<b>\n<?php\n    trim(\$x);\n")))
        ->toBe([['name' => 'trim', 'line' => 3]]);
});
