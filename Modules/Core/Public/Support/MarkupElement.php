<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Internal\Support\MarkupAttributes;
use Modules\Core\Public\Exceptions\MarkupParseFailedException;

// `inner` is nullable on purpose: an element whose closing tag the walk never
// reached has unknown content, and answering that with an empty string is the
// reading this whole seam exists to stop.
final class MarkupElement
{
    /**
     * @var array<string, string>|null
     */
    private ?array $parsed = null;

    public function __construct(
        public readonly string $name,
        public readonly int $offset,
        public readonly string $startTag,
        public readonly ?string $inner,
    ) {}

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $this->parsed ??= MarkupAttributes::of($this->startTag);

        return $this->parsed;
    }

    public function attribute(string $name): ?string
    {
        return $this->attributes()[$name] ?? null;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes());
    }

    /**
     * @return list<string>
     */
    public function classes(): array
    {
        $list = $this->attribute('class') ?? '';

        return array_values(array_filter(explode(' ', str_replace(["\n", "\t"], ' ', $list)), static fn (string $token): bool => $token !== ''));
    }

    public function innerOrFail(): string
    {
        if ($this->inner === null) {
            throw new MarkupParseFailedException('a <'.$this->name.'> whose closing tag never arrives', $this->startTag);
        }

        return $this->inner;
    }

    public function text(): string
    {
        return MarkupSource::text($this->innerOrFail());
    }

    public function line(string $source): int
    {
        return substr_count(substr($source, 0, $this->offset), "\n") + 1;
    }
}
