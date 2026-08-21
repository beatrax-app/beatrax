<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Core\Public\Support\Lang;

/**
 * @link ../../../../.docs/features/notifications/reader-language-copy.md
 */
final readonly class CopyLine
{
    /**
     * @param  array<string, string|int|float|CopyParam>  $replace
     */
    private function __construct(
        public string $key,
        public array $replace,
        public ?int $count,
    ) {}

    /**
     * @param  array<string, string|int|float|CopyParam>  $replace
     */
    public static function of(string $key, array $replace = []): self
    {
        return new self($key, $replace, null);
    }

    // The plural form is chosen at read time too: a language with more plural
    // forms than the writer's would otherwise be stuck with the writer's pick.
    /**
     * @param  array<string, string|int|float|CopyParam>  $replace
     */
    public static function plural(string $key, int $count, array $replace = []): self
    {
        return new self($key, $replace + ['count' => $count], $count);
    }

    // Null once the key no longer has a line: Lang answers a miss with the key
    // itself, and a raw key is not a sentence to hand a reader. The caller
    // decides what to show instead, and the two callers want different things.
    public function render(): ?string
    {
        if (Lang::get($this->key) === $this->key) {
            return null;
        }

        $replace = [];
        foreach ($this->replace as $name => $value) {
            $replace[$name] = $value instanceof CopyParam ? $value->render() : $value;
        }

        return $this->count === null
            ? Lang::get($this->key, $replace)
            : Lang::choice($this->key, $this->count, $replace);
    }

    /**
     * @return array{key: string, replace: array<string, string|int|float|array{kind: string, value: string}>, count: int|null}
     */
    public function toArray(): array
    {
        $replace = [];
        foreach ($this->replace as $name => $value) {
            $replace[$name] = $value instanceof CopyParam ? $value->toArray() : $value;
        }

        return ['key' => $this->key, 'replace' => $replace, 'count' => $this->count];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $key = $raw['key'] ?? null;
        $count = $raw['count'] ?? null;

        if (! is_string($key) || $key === '' || ! (is_int($count) || $count === null)) {
            return null;
        }

        $replace = self::decodeReplace($raw['replace'] ?? []);

        return $replace === null ? null : new self($key, $replace, $count);
    }

    /**
     * @return array<string, string|int|float|CopyParam>|null
     */
    private static function decodeReplace(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $replace = [];
        foreach ($raw as $name => $value) {
            if (! is_string($name)) {
                return null;
            }

            if (is_array($value)) {
                $param = CopyParam::fromArray($value);
                if ($param === null) {
                    return null;
                }
                $replace[$name] = $param;

                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                return null;
            }

            $replace[$name] = $value;
        }

        return $replace;
    }
}
