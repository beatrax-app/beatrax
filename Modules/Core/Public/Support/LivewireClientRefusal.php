<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Exception;
use Illuminate\Container\BoundMethod;
use Illuminate\Contracts\Container\BindingResolutionException;
use Livewire\Component;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\ImplicitlyBoundMethod;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use TypeError;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-refused-write-answering-as-a-server-fault
 */
// Every write a /livewire/update payload can be refused for, and the one
// status each refusal answers with. Both roots read this: a mapping present
// on one bundle and missing on the other is two answers to one payload.
final class LivewireClientRefusal
{
    // Livewire throws this as a bare \Exception, so the message is the only
    // thing naming it. Held as a constant because the guard test asserts the
    // framework still produces it, which is the whole warning on an upgrade.
    public const string UNSUPPORTED_TYPE_MESSAGE = 'Property type not supported in Livewire for property:';

    private const string DESCENT_FRAME = 'recursivelySetValue';

    // Livewire only reaches this frame while invoking a method the `calls`
    // half of an update payload named, so it is what separates a refused call
    // from the same exception class raised anywhere else in the app.
    private const string CALL_FRAME = 'callMethods';

    private const string ARGUMENT_RESOLVER_FRAME = 'resolveMethodDependencies';

    private const int REFUSED_BY_THE_LOCK = 403;

    private const int PATH_THE_COMPONENT_CANNOT_ACCEPT = 400;

    private const int NO_READER_THE_CALL_CAN_RUN_AS = 401;

    // Livewire's own message json_encodes the value it stopped on, which for a
    // deep path is whatever the property holds -- a search term, a decrypted
    // column. HttpException messages survive into a production JSON body, so
    // this one names the shape of the refusal and never the value.
    private const string DESCENT_REFUSED = 'Update path names a value that cannot be descended into.';

    // Names the shape rather than repeating the container's own words, which
    // spell out the whole reflected parameter signature.
    private const string ARGUMENT_MISSING = 'Call omits an argument the component method requires.';

    // PHP's own TypeError message carries the whole declared signature and the
    // absolute path of the file that made the call, and an HttpException
    // message is the entire body a production build returns.
    private const string ARGUMENT_TYPE_REFUSED = 'Call passes an argument of a type the component method cannot accept.';

    private const string NO_READER_REFUSED = 'Call names a method that needs an authenticated reader.';

    public static function refusal(Throwable $e): ?HttpException
    {
        // Status and body together, because the pair is the answer: only the
        // lock and the missing reader leave the component's own path, and a
        // Livewire message is safe to pass on where one exists.
        $refusal = match (true) {
            $e instanceof CannotUpdateLockedPropertyException => [self::REFUSED_BY_THE_LOCK, $e->getMessage()],
            $e instanceof PublicPropertyNotFoundException,
            $e instanceof MethodNotFoundException => [self::PATH_THE_COMPONENT_CANNOT_ACCEPT, $e->getMessage()],
            self::isDescentIntoALeaf($e) => [self::PATH_THE_COMPONENT_CANNOT_ACCEPT, self::DESCENT_REFUSED],
            self::isArgumentTheCallOmitted($e) => [self::PATH_THE_COMPONENT_CANNOT_ACCEPT, self::ARGUMENT_MISSING],
            self::isArgumentTypeTheCallChose($e) => [self::PATH_THE_COMPONENT_CANNOT_ACCEPT, self::ARGUMENT_TYPE_REFUSED],
            self::isCallWithNoAuthenticatedReader($e) => [self::NO_READER_THE_CALL_CAN_RUN_AS, self::NO_READER_REFUSED],
            default => null,
        };

        return $refusal === null ? null : new HttpException($refusal[0], $refusal[1], $e);
    }

    // The splat passes the payload's own `params` uncoerced, so the throw lands
    // ON the component method: its frame tops the trace and the container's
    // invoker is the one below. A TypeError from inside the method body has app
    // code in that second frame, and stays the 500 it is.
    private static function isArgumentTypeTheCallChose(Throwable $e): bool
    {
        if (! $e instanceof TypeError) {
            return false;
        }

        $trace = $e->getTrace();
        $refused = $trace[0]['class'] ?? null;

        return is_string($refused)
            && is_subclass_of($refused, Component::class)
            && ($trace[1]['class'] ?? null) === BoundMethod::class
            && self::hasFrame($e, HandleComponents::class, self::CALL_FRAME);
    }

    // A component method reachable on a route outside the auth group, asked
    // for the reader there is none of. The frame is what keeps a scheduled
    // task or a console command resolving the same accessor a server fault:
    // only a payload can name a method here.
    private static function isCallWithNoAuthenticatedReader(Throwable $e): bool
    {
        return $e instanceof NotAuthenticatedException
            && self::hasFrame($e, HandleComponents::class, self::CALL_FRAME);
    }

    // A required scalar the payload simply did not send. The container's throw
    // site is only reachable for a parameter with no class and no default, and
    // the two Livewire frames pin it to a method an update payload named -- so
    // it can never be one of the app's own missing bindings.
    private static function isArgumentTheCallOmitted(Throwable $e): bool
    {
        return $e instanceof BindingResolutionException
            && self::hasFrame($e, HandleComponents::class, self::CALL_FRAME)
            && self::hasFrame($e, ImplicitlyBoundMethod::class, self::ARGUMENT_RESOLVER_FRAME);
    }

    private static function hasFrame(Throwable $e, string $class, string $function): bool
    {
        return array_any($e->getTrace(), fn (array $frame): bool => ($frame['class'] ?? null) === $class && $frame['function'] === $function);
    }

    // `filterAccounts.0.0` resolves a synthesizer for the container at each
    // segment, and at `0` the container is an int no synthesizer matches.
    // #[Locked] cannot reach this: it is generic to every array property whose
    // elements are scalars, including ones the browser may legitimately bind.
    private static function isDescentIntoALeaf(Throwable $e): bool
    {
        if ($e::class !== Exception::class) {
            return false;
        }

        if (! str_starts_with($e->getMessage(), self::UNSUPPORTED_TYPE_MESSAGE)) {
            return false;
        }

        // The same throw answers a component genuinely holding a type Livewire
        // cannot dehydrate, which IS a server fault and has to stay a 500.
        // Only the update path descends through this frame, so it is what
        // separates the two.
        return array_any($e->getTrace(), fn (array $frame): bool => ($frame['class'] ?? null) === HandleComponents::class
            && $frame['function'] === self::DESCENT_FRAME);
    }
}
