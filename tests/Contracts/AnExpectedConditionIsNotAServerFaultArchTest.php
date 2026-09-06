<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Contracts\Support\HttpEntryPointThrows;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-expected-condition-answering-as-a-server-fault
 */

// The three families the framework answers with a status of its own. Anything
// else an entry point lets out reaches the generic handler, and the generic
// handler has exactly one answer: 500, the status that says the server was at
// fault for what the client sent.
/** @return array<string, Throwable> family => an instance to answer with */
function expectedConditionFamilies(): array
{
    return [
        HttpExceptionInterface::class => new NotFoundHttpException('Nothing here.'),
        HttpResponseException::class => new HttpResponseException(new Response('', Response::HTTP_NO_CONTENT)),
        ValidationException::class => ValidationException::withMessages(['field' => 'Refused.']),
    ];
}

function expectedConditionCarriesItsOwnAnswer(string $thrown): bool
{
    if (! class_exists($thrown)) {
        return false;
    }

    foreach (array_keys(expectedConditionFamilies()) as $family) {
        if (is_a($thrown, $family, true)) {
            return true;
        }
    }

    return false;
}

function expectedConditionStatusFor(Throwable $e): int
{
    $request = Request::create('/an-expected-condition', 'POST');
    $request->headers->set('Accept', 'application/json');

    return app(ExceptionHandler::class)->render($request, $e)->getStatusCode();
}

it('lets no HTTP entry point raise an exception that carries no answer of its own', function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $offences = [];
    $files = HttpEntryPointThrows::files($router);

    // Two hundred and eight files sit under an Http/ directory today, before
    // the routed classes are added. A walk that opened none of them would
    // report every entry point as answering for itself.
    expect(count($files))->toBeGreaterThan(
        50,
        'Only '.count($files).' HTTP entry points were read, so an empty offender list says nothing.',
    );

    foreach ($files as $file) {
        foreach (HttpEntryPointThrows::unguarded((string) file_get_contents($file)) as $throw) {
            if (expectedConditionCarriesItsOwnAnswer($throw['class'])) {
                continue;
            }

            $offences[] = str_replace(base_path().'/', '', $file).':'.$throw['line'].' raises '.$throw['class'];
        }
    }

    expect($offences)->toBe([], implode("\n", array_merge(
        ['These raise an exception the generic handler can only answer 500 with:'],
        $offences,
        ['A condition a client or the environment can trigger is not a server fault. Give it an '
            .'exception that names its own status, or answer it where the reader is — the OAuth '
            .'callbacks flash a line and redirect rather than raising at all.'],
    )));
});

// The rule above is only worth anything if the two halves really do answer
// differently, so both halves are measured through the live handler rather
// than assumed from the class name.
it('answers each trusted family under 500', function (): void {
    foreach (expectedConditionFamilies() as $family => $instance) {
        expect(expectedConditionStatusFor($instance))->toBeLessThan(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $family.' no longer answers with a status of its own, so every entry point trusting it now 500s.',
        );
    }
});

it('answers an exception carrying no status of its own with a 500', function (): void {
    expect(expectedConditionStatusFor(new RuntimeException('A genuine fault.')))
        ->toBe(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'the generic handler has one answer, and the rule above is only worth something while it is this one',
        );
});

it('reads a throw nothing catches, and leaves a guarded one and a trusted family alone', function (): void {
    // The planted namespace says Public deliberately: pinnedCrossModuleInternalImports
    // scans this file too, and reads an inline Modules\<X>\Internal\ reference — a
    // heredoc included — as a boundary crossing nobody pinned.
    $raising = <<<'PHP'
        <?php
        namespace Modules\Planted\Public\Http;
        use RuntimeException;
        final class PlantedEntryPoint
        {
            public function __invoke(): void
            {
                throw new RuntimeException('nothing answers for this');
            }
        }
        PHP;

    $guarded = <<<'PHP'
        <?php
        namespace Modules\Planted\Public\Http;
        use RuntimeException;
        final class PlantedGuardedEntryPoint
        {
            public function __invoke(): void
            {
                try {
                    throw new RuntimeException('answered where the reader is');
                } catch (RuntimeException $e) {
                    $this->flash($e);
                }
            }
        }
        PHP;

    expect(array_column(HttpEntryPointThrows::unguarded($raising), 'class'))
        ->toBe(['RuntimeException'], 'the unguarded raise is the whole defect, and the reader has to find it');

    expect(HttpEntryPointThrows::unguarded($guarded))
        ->toBe([], 'a throw its own file catches never reaches the generic handler');

    expect(expectedConditionCarriesItsOwnAnswer('RuntimeException'))
        ->toBeFalse('a RuntimeException names no status, which is why it can only be answered 500');

    expect(expectedConditionCarriesItsOwnAnswer(NotFoundHttpException::class))
        ->toBeTrue('the three trusted families are what an entry point may let out');
});
