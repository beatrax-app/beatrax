<?php

declare(strict_types=1);

use Tests\Contracts\Support\ControllerShape;
use Tests\Contracts\Support\SonarClassShape;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/conventions/a-controller-hands-the-work-to-an-action.md
 */

// A controller resolves input, delegates, and returns a response. Everything
// between those three is somebody else's: 1,973 lines across fourteen of them
// held token exchanges, compensating rollbacks, tier allow-lists and two
// hand-written open-redirect guards, none of which a route is the right place
// to read.
//
// Nothing enforced that. BoundaryArchTest mentioned a controller in a comment
// and PublicSurfaceArchTest governs which namespace a class may live in;
// neither has an opinion about what one does.
it('leaves no controller carrying work an action should own', function (): void {
    $files = ControllerShape::files();

    // A walk that stops reading finds no controller and reports a clean tree.
    expect(count($files))->toBeGreaterThan(10);

    $offenders = [];

    foreach ($files as $path) {
        foreach (ControllerShape::offences((string) file_get_contents($path)) as $offence) {
            $offenders[] = str_replace(base_path().'/', '', $path).' — '.$offence;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These controllers carry more than an HTTP entry point:',
        ...$offenders,
        '',
        'The rule is four measurable things, and a controller has to clear',
        'every one of them:',
        '',
        '  1. It names no database type. No Illuminate\\Database\\, no',
        '     Illuminate\\Contracts\\Database\\, no DB facade, no Eloquent model',
        '     under Modules\\<X>\\Models\\. A connection or a model in scope is',
        '     one ->update() away from making the HTTP layer a writer, and the',
        '     row it would write has no test that reaches it by any other route.',
        '  2. No method runs more than '.ControllerShape::MAX_STATEMENTS.' statements. Closures the method',
        '     declares count towards its own total, so moving a body into',
        '     new StreamedResponse(function () { ... }) does not hide it.',
        '  3. No method scores more than '.ControllerShape::MAX_COMPLEXITY.' on cognitive complexity — well',
        '     under the tree-wide ceiling of 15, because deciding is the part',
        '     that belongs in an action.',
        '  4. The class declares at most '.ControllerShape::MAX_METHODS.' methods besides its constructor,',
        '     so logic that will not fit in one entry point cannot simply be',
        '     spread across private helpers instead.',
        '',
        'The fix is a single-purpose action class beside the ones already in',
        'Modules/<X>/Internal/Actions/ and Modules/<X>/Public/Actions/ — Internal',
        'unless a neighbouring module needs to call it. The controller keeps the',
        'parts that are genuinely HTTP and nothing else: reading the request,',
        'choosing a status or a redirect, setting headers, and the one-shot',
        'session glue an OAuth round trip needs. Those are not violations of',
        'this rule and never were; the numbers above are sized to leave room',
        'for them, including for an SSE pump written out in full.',
        '',
        'There is no pinned list to add to. The default branch carries no',
        'controller over any of the four, so every entry above is something',
        'this branch introduced.',
    ]));
});

// The guard is only worth its runtime if it fails on the shapes it describes.
// Each source below is the smallest thing that breaks exactly one of the four,
// and the last is a controller that breaks none.
it('reports each of the four shapes it names, and stays quiet on a thin one', function (): void {
    $reaching = '<?php use Illuminate\Database\DatabaseManager; class C { public function __invoke(): void { $this->db->connection(); } }';
    $modelled = '<?php use Modules\Core\Models\User; class C { public function __invoke(User $u): void {} }';

    $long = '<?php class C { public function __invoke(): void { ';
    for ($i = 0; $i <= ControllerShape::MAX_STATEMENTS; $i++) {
        $long .= '$a'.$i.' = '.$i.'; ';
    }
    $long .= '} }';

    $branchy = '<?php class C { public function __invoke(): int { ';
    for ($i = 0; $i <= ControllerShape::MAX_COMPLEXITY; $i++) {
        $branchy .= 'if ($a'.$i.') { return '.$i.'; } ';
    }
    $branchy .= 'return -1; } }';

    $sprawling = '<?php class C { public function __construct() {} ';
    for ($i = 0; $i <= ControllerShape::MAX_METHODS; $i++) {
        $sprawling .= 'private function m'.$i.'(): void {} ';
    }
    $sprawling .= '}';

    $thin = '<?php class C { public function __construct(private A $a) {} '
        .'public function __invoke(R $r): X { return new X(($this->a)($r->input())); } }';

    expect(ControllerShape::offences($reaching))->toBe(['names Illuminate\\Database\\']);
    expect(ControllerShape::offences($modelled))->toBe(['names an Eloquent model under Modules\\<X>\\Models\\']);
    expect(ControllerShape::offences($long))->toBe(['__invoke() runs 13 statements (at most 12)']);
    expect(ControllerShape::offences($branchy))->toBe(['__invoke() scores 7 on cognitive complexity (at most 6)']);
    expect(ControllerShape::offences($sprawling))->toBe(['C declares 8 methods besides its constructor (at most 7)']);
    expect(ControllerShape::offences($thin))->toBe([]);
});

// The two readings a size rule gets wrong on the shapes this tree actually
// holds: a `for` header carries two semicolons that end nothing, and a closure
// handed to a response constructor is the enclosing method's own work.
it('reads no statement out of a for header, and folds a closure into the method that declares it', function (): void {
    $loop = '<?php class C { public function __invoke(): void { for ($i = 0; $i < 3; $i++) { echo $i; } } }';
    $closure = '<?php class C { public function __invoke(): X { return new X(function (): void { $a = 1; $b = 2; }); } }';

    $statementsIn = static function (string $source): int {
        $tokens = SonarSourceFiles::tokens($source);
        $brackets = SonarSourceFiles::brackets($tokens);
        $types = SonarClassShape::types($tokens, $brackets);
        $methods = SonarClassShape::methods($tokens, $brackets, $types[0]['open'], $types[0]['close']);

        return ControllerShape::statements($tokens, $brackets, $methods[0]['nameIndex']);
    };

    expect($statementsIn($loop))->toBe(1);
    expect($statementsIn($closure))->toBe(3);
});
