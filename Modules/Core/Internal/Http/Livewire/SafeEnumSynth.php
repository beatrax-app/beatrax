<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use BackedEnum;
use Livewire\Mechanisms\HandleComponents\Synthesizers\EnumSynth;
use ReflectionProperty;

// Registered ahead of the framework's own enum synth, under its key, so it
// replaces it for every component.
/**
 * @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#an-enum-typed-livewire-property-hydrated-before-its-lock
 */
final class SafeEnumSynth extends EnumSynth
{
    /** @var mixed Untyped on EnumSynth, so a narrower type here breaks the override. */
    public static $key = 'enm';

    /**
     * @param  mixed  $type
     * @param  mixed  $value
     */
    public static function hydrateFromType($type, $value): ?BackedEnum
    {
        if (! is_string($type) || ! is_subclass_of($type, BackedEnum::class)) {
            return null;
        }

        return self::caseOrNull($type, $value);
    }

    /**
     * @param  mixed  $value
     * @param  mixed  $meta
     * @param  ?callable  $callback
     */
    public function hydrate($value, $meta, $callback = null): ?BackedEnum
    {
        $class = is_array($meta) ? ($meta['class'] ?? null) : null;
        if (! is_string($class) || ! is_subclass_of($class, BackedEnum::class)) {
            return null;
        }

        $case = self::caseOrNull($class, $value);
        if ($case !== null) {
            return $case;
        }

        return $this->clearsToNull($value) ? null : $this->currentCase($class);
    }

    /**
     * @param  class-string<BackedEnum>  $class
     */
    private static function caseOrNull(string $class, mixed $value): ?BackedEnum
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $wanted = (string) $value;
        foreach ($class::cases() as $case) {
            if ((string) $case->value === $wanted) {
                return $case;
            }
        }

        return null;
    }

    // An emptied <select> is how a nullable enum is cleared, so that still
    // clears. On a property that cannot hold null the framework answers the
    // same value by unsetting it, which leaves it uninitialised and fatal to
    // the next method that reads it — there, the held value wins instead.
    private function clearsToNull(mixed $value): bool
    {
        if ($value !== null && $value !== '') {
            return false;
        }

        $type = $this->targetProperty()?->getType();

        return $type === null || $type->allowsNull();
    }

    /**
     * @param  class-string<BackedEnum>  $class
     */
    private function currentCase(string $class): ?BackedEnum
    {
        $property = $this->targetProperty();
        $component = $this->context->component;
        if ($property === null || ! is_object($component) || ! $property->isInitialized($component)) {
            return null;
        }

        $current = $property->getValue($component);

        return $current instanceof $class ? $current : null;
    }

    private function targetProperty(): ?ReflectionProperty
    {
        $component = $this->context->component;
        $path = $this->path;

        return is_object($component) && is_string($path) && property_exists($component, $path)
            ? new ReflectionProperty($component, $path)
            : null;
    }
}
