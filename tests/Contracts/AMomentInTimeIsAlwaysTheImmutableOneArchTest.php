<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/one-class-for-a-moment-in-time.md
 */

// Below this the walk read almost nothing and a clean answer means the scope
// moved, not that the tree is clean.
const MUTABLE_DATE_FILE_FLOOR = 5000;

// Laravel's Carbon is a subclass of Carbon's, so both names reach the same
// mutating methods and both have to be named here.
const MUTABLE_DATE_CLASSES = ['Carbon\Carbon', 'Illuminate\Support\Carbon'];

/**
 * Every place $source calls, instantiates or type-declares a mutable date
 * class, as the name it used and the line it stands on.
 *
 * A docblock is deliberately outside this: an Eloquent attribute with no
 * immutable cast really is returned as a mutable Carbon, and a @property line
 * saying otherwise would be a lie about the running code rather than a rule
 * being kept.
 *
 * @return list<array{line: int, spelling: string}>
 */
function mutableDateUses(string $source): array
{
    $tokens = @token_get_all($source);

    if (! is_array($tokens)) {
        return [];
    }

    $significant = [];

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $significant[] = is_array($token) ? [$token[0], $token[1], $token[2]] : [-1, $token, 0];
    }

    $bound = [];
    $found = [];
    $line = 1;

    foreach ($significant as $index => [$id, $text, $at]) {
        if ($at > 0) {
            $line = $at;
        }

        if ($id === T_USE && ($significant[$index + 1][0] ?? 0) !== T_FUNCTION) {
            $imported = ltrim($significant[$index + 1][1] ?? '', '\\');

            if (in_array($imported, MUTABLE_DATE_CLASSES, true)) {
                $alias = ($significant[$index + 2][0] ?? 0) === T_AS
                    ? ($significant[$index + 3][1] ?? '')
                    : substr($imported, (int) strrpos($imported, '\\') + 1);

                $bound[$alias] = true;
            }

            continue;
        }

        $names = $id === T_STRING ? [$text] : [];

        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && in_array(ltrim($text, '\\'), MUTABLE_DATE_CLASSES, true)) {
            $names = [$text];
            $bound[$text] = true;
        }

        foreach ($names as $name) {
            if (! isset($bound[$name])) {
                continue;
            }

            $after = $significant[$index + 1] ?? [0, ''];
            $before = $significant[$index - 1] ?? [0, ''];

            $calls = $after[1] === '::';
            $builds = $before[0] === T_NEW;
            // A type declaration: `Carbon $x`, `?Carbon $x`, `): Carbon`.
            $declares = $after[0] === T_VARIABLE || $before[1] === ':' || $before[1] === '?';

            if ($calls || $builds || $declares) {
                $found[] = ['line' => $line, 'spelling' => $name];
            }
        }
    }

    return $found;
}

it('reads a moment in time off one class, so no caller has to know whether a method moved the value it was given', function (): void {
    $files = RepoTree::files(RepoTree::EVERY_PHP_FILE);

    expect(count($files))->toBeGreaterThan(
        MUTABLE_DATE_FILE_FLOOR,
        'The walk opened '.count($files).' PHP files, so a clean answer here is a walk that read almost nothing.'
    );

    $offenders = [];
    $root = RepoTree::root().'/';

    foreach ($files as $path) {
        // A .blade.php ends in .php and lands in this walk, and token_get_all
        // reads a whole template as one T_INLINE_HTML.
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        foreach (mutableDateUses($source) as $use) {
            $offenders[] = str_replace($root, '', $path).':'.$use['line'].'  '.$use['spelling'];
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'This calls, builds or type-declares a mutable date class. Carbon\Carbon and Illuminate\Support\Carbon',
        'both mutate in place, so `$due->addDay()` moves the value its caller still holds, and the tree reads',
        'CarbonImmutable everywhere else. Use Carbon\CarbonImmutable. Carbon::setTestNow() and',
        'CarbonImmutable::setTestNow() are literally the same call — one method on one shared store — so the',
        'mutable spelling in a test buys nothing at all. Offenders:',
        ...array_slice($offenders, 0, 40),
        count($offenders) > 40 ? '… and '.(count($offenders) - 40).' more' : '',
    ]));
});

// The reader half, proved against planted sources: a reader that stopped
// resolving the import would report a clean tree, and one that resolved a
// bare name without checking what it was bound to would report every
// CarbonImmutable call in the repository.
it('tells a mutable date class from the immutable one bound to a similar name', function (string $body, array $spellings): void {
    expect(array_column(mutableDateUses('<?php '.$body), 'spelling'))->toBe($spellings, 'The reader misread: '.$body);
})->with([
    'the Carbon package class' => ["use Carbon\Carbon;\n\$n = Carbon::now();", ['Carbon']],
    'the Laravel subclass' => ["use Illuminate\Support\Carbon;\n\$n = Carbon::now();", ['Carbon']],
    'an alias over the mutable one' => ["use Carbon\Carbon as Moment;\n\$n = Moment::now();", ['Moment']],
    'the fully qualified name' => ['$n = \Carbon\Carbon::now();', ['\Carbon\Carbon']],
    'construction' => ["use Carbon\Carbon;\n\$n = new Carbon('2026-01-01');", ['Carbon']],
    'a parameter type' => ["use Carbon\Carbon;\nfunction f(Carbon \$at): void {}", ['Carbon']],
    'a nullable return type' => ["use Carbon\Carbon;\nfunction f(): ?Carbon {}", ['Carbon']],
    'the immutable class' => ["use Carbon\CarbonImmutable;\n\$n = CarbonImmutable::now();", []],
    'the immutable interface' => ["use Carbon\CarbonInterface;\nfunction f(CarbonInterface \$at): void {}", []],
    'a local class that happens to be called Carbon' => ["use App\Support\Carbon;\n\$n = Carbon::now();", []],
    'the name with no import at all' => ['$n = Carbon::now();', []],
    'the name written into a string' => ["use Carbon\Carbon;\n\$hint = 'Carbon::now() was here';", []],
    'a function import, which binds no class' => ["use function Carbon\Carbon;\n\$n = CarbonImmutable::now();", []],
]);
